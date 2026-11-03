<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\ServiceReservation;
use App\Services\ReservationTime;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationAvailabilityTest extends TestCase
{
    use ReservationFixtures;

    public function test_no_configuration_or_closed_days_never_mean_free_availability(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        DB::table('provider_weekly_intervals')->delete(); DB::table('provider_availability_settings')->delete();
        $this->actingAs($client)->get(route('reservations.create', $inquiry))->assertStatus(409)->assertSee('no tiene horarios');
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
        $this->publishSchedule($provider, changes: ['intervals' => []]);
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_whole_hour_must_fit_one_interval_and_blocked_dates_are_rechecked_on_confirmation(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->publishSchedule($provider, revision: 1, changes: ['intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '10:00'], ['day_of_week' => 1, 'start' => '10:00', 'end' => '11:00']]]);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData(['availability_revision' => 2, 'time' => '09:30']))->assertStatus(409);
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData(['availability_revision' => 2, 'date' => '2030-01-08']))->assertStatus(409);
        $reservation = $this->proposeReservation($inquiry, ['availability_revision' => 2]);
        $this->publishSchedule($provider, revision: 2, changes: ['blocked_dates' => ['2030-01-07']]);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
        $this->publishSchedule($provider, revision: 3);
        $this->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_paused_service_inactive_category_and_revoked_client_block_new_confirmations(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $inquiry->service->update(['status' => 'paused']);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $inquiry->service->update(['status' => 'published']); $inquiry->service->category->update(['is_active' => false]);
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $inquiry->service->category->update(['is_active' => true]); $client->roles()->detach();
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        // La denegación de nuevas confirmaciones no impide que el prestador cierre la propuesta.
        $this->patch(route('reservations.reject', $reservation), ['version' => 1])->assertStatus(303);
    }

    public function test_explicit_horizon_and_future_start_are_independent_of_application_timezone(): void
    {
        [$client, , $inquiry] = $this->reservationContext(); config(['app.timezone' => 'Asia/Tokyo', 'reservations.horizon_days' => 2]);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-04']))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-01', 'time' => '06:00']))->assertStatus(409);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-03']))->assertStatus(303);
        $this->assertSame('2030-01-03 15:00:00', ServiceReservation::sole()->starts_at_utc);
        $this->assertSame('2030-01-03 16:00:00', ServiceReservation::sole()->ends_at_utc);
    }

    public function test_stale_configuration_form_conflicts_and_confirmed_times_survive_zone_and_schedule_edits(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->publishSchedule($provider, revision: 1);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
        $reservation = $this->proposeReservation($inquiry, ['availability_revision' => 2]);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $before = $reservation->fresh()->getAttributes();
        $this->publishSchedule($provider, 'Asia/Tokyo', 2, ['intervals' => [], 'blocked_dates' => ['2030-01-07']]);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->actingAs($client)->get(route('reservations.show', $reservation))->assertOk()->assertSee('America/Mexico_City')->assertSee('09:00–10:00')->assertSee('Reserva confirmada');
    }

    public function test_changed_zone_requires_a_new_proposal_but_current_weekly_intervals_are_revalidated(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->publishSchedule($provider, 'America/New_York', 1);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->publishSchedule($provider, 'America/Mexico_City', 2, ['intervals' => [['day_of_week' => 1, 'start' => '10:00', 'end' => '12:00']]]);
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->assertSame('2030-01-07 15:00:00', $reservation->fresh()->starts_at_utc);
        $this->assertSame(1, $reservation->events()->count());
    }

    public function test_nonexistent_ambiguous_and_offset_crossing_hours_are_rejected_explicitly(): void
    {
        $time = app(ReservationTime::class);
        foreach ([['2030-03-10', '02:30', 'America/New_York'], ['2030-03-10', '01:30', 'America/New_York'],
            ['2030-11-03', '01:30', 'America/New_York'], ['2030-11-03', '00:30', 'America/New_York'],
            ['2030-10-06', '02:00', 'Australia/Lord_Howe'], ['2030-10-06', '01:45', 'Australia/Lord_Howe'],
            ['2030-01-07', '23:00', 'UTC']] as [$date, $hour, $zone]) {
            try { $time->resolve($date, $hour, $zone); $this->fail('No normalizar cambios DST ni cruce de medianoche.'); }
            catch (\Illuminate\Validation\ValidationException $exception) { $this->assertArrayHasKey('time', $exception->errors()); }
        }
    }

    public function test_concrete_dst_conversions_round_trip_and_keep_sixty_elapsed_minutes(): void
    {
        $time = app(ReservationTime::class);
        foreach ([['2030-03-10', '03:30', 'America/New_York', '2030-03-10 07:30:00'],
            ['2030-11-03', '02:30', 'America/New_York', '2030-11-03 07:30:00'],
            ['2030-01-07', '09:00', 'Asia/Kathmandu', '2030-01-07 03:15:00'],
            ['2030-01-01', '08:00', 'Pacific/Kiritimati', '2029-12-31 18:00:00'],
            ['2030-02-01', '08:00', 'Etc/UTC', '2030-02-01 08:00:00']] as [$date, $hour, $zone, $expected]) {
            $slot = $time->resolve($date, $hour, $zone);
            $this->assertSame($expected, $slot['starts_at_utc']);
            $start = new \DateTimeImmutable($slot['starts_at_utc'], new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($slot['ends_at_utc'], new \DateTimeZone('UTC'));
            $this->assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
            $this->assertSame($date.' '.$hour, $start->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i'));
        }
    }

    public function test_provider_day_and_year_boundaries_control_the_horizon_not_the_server_day(): void
    {
        [$client, , $inquiry] = $this->reservationContext('Pacific/Kiritimati');
        $this->travelTo(\Carbon\Carbon::parse('2029-12-31 12:00:00', 'UTC')); config(['app.timezone' => 'America/Los_Angeles', 'reservations.horizon_days' => 1]);
        $this->actingAs($client)->get(route('reservations.create', $inquiry))->assertOk()->assertSee('min="2030-01-01"', false)->assertSee('max="2030-01-02"', false);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-01', 'time' => '08:00']))->assertStatus(303);
        $this->assertSame('2029-12-31 18:00:00', ServiceReservation::sole()->starts_at_utc);
        $this->assertSame('2030-01-01', ServiceReservation::sole()->local_date);
    }

    public function test_http_proposals_reject_dst_ambiguity_instead_of_persisting_a_normalized_instant(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext('America/New_York');
        $this->publishSchedule($provider, 'America/New_York', 1, ['intervals' => [['day_of_week' => 7, 'start' => '00:00', 'end' => '23:59']]]);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-03-10', 'time' => '02:30', 'availability_revision' => 2]))->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-03-10', 'time' => '01:30', 'availability_revision' => 2]))->assertUnprocessable();
        $reservation = $this->proposeReservation($inquiry, ['date' => '2030-03-10', 'time' => '03:30', 'availability_revision' => 2]);
        $this->assertSame('2030-03-10 07:30:00', $reservation->starts_at_utc);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'reject', ['version' => 1]);
        $this->travelTo(\Carbon\Carbon::parse('2030-10-01 12:00:00', 'UTC'));
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-11-03', 'time' => '01:30', 'availability_revision' => 2]))->assertUnprocessable();
        $fall = $this->proposeReservation($inquiry, ['date' => '2030-11-03', 'time' => '02:30', 'availability_revision' => 2]);
        $this->assertSame('2030-11-03 07:30:00', $fall->starts_at_utc);
        $this->assertDatabaseCount('service_reservations', 2);
    }
}
