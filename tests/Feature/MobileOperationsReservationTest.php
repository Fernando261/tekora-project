<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\ProviderAvailability;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileOperationsReservationTest extends TestCase
{
    use MobileOperationsFixtures;

    public function test_private_options_revision_and_proposal_cancel_use_the_existing_workflow(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $base = '/api/v1/inquiries/'.$inquiry->id;
        $options = $this->getJson($base.'/reservation-options', $this->bearer($client))->assertOk()
            ->assertJsonPath('data.availability_revision', 1)->assertJsonPath('data.timezone', 'America/Mexico_City')
            ->assertJsonPath('data.duration_minutes', 60)->assertJsonPath('data.guaranteed', false)
            ->assertJsonPath('data.min_date', '2030-01-01')->assertJsonPath('data.max_date', '2030-04-01');
        $this->assertCount(7, $options->json('data.intervals'));
        $public = $this->getJson('/api/v1/services/'.$inquiry->service_id.'/availability')->assertOk();
        $this->assertArrayNotHasKey('availability_revision', $public->json('data'));
        $data = $this->reservationData();
        $created = $this->postJson($base.'/reservations', $data, $this->bearer($client))->assertCreated()
            ->assertJsonPath('data.status', 'requested')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.starts_at', '2030-01-07T15:00:00.000000Z')->assertJsonPath('data.ends_at', '2030-01-07T16:00:00.000000Z');
        $id = $created->json('data.id');
        $this->postJson($base.'/reservations', $data, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($base.'/reservations', array_replace($data, ['time' => '10:00']), $this->bearer($client))->assertConflict();
        $this->getJson($base.'/reservation-options', $this->bearer($client))->assertConflict();
        $this->getJson('/api/v1/reservations/'.$id, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/api/v1/reservations/'.$id.'/cancel', ['version' => 1], $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/reservations/'.$id.'/cancel', ['version' => 1], $this->bearer($client))->assertConflict();
        $this->getJson('/api/v1/reservations?status=cancelled&per_page=1', $this->bearer($client))->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('service_reservations', 1);
        $this->assertDatabaseCount('reservation_events', 2);
    }

    public function test_reservations_of_third_parties_and_provider_experience_are_invisible(): void
    {
        [$client, $dual, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        foreach ([$dual, $this->account(RoleName::Client)] as $actor) {
            $this->getJson('/api/v1/reservations', $this->bearer($actor))->assertOk()->assertJsonCount(0, 'data');
            foreach ([$reservation->id, 999999] as $id) {
                $this->getJson('/api/v1/reservations/'.$id, $this->bearer($actor))->assertNotFound();
                $this->patchJson('/api/v1/reservations/'.$id.'/cancel', ['version' => []], $this->bearer($actor))->assertNotFound();
            }
        }
        foreach (['confirm', 'reject', 'complete'] as $action) {
            $this->patchJson('/api/v1/reservations/'.$reservation->id.'/'.$action, ['version' => 1], $this->bearer($dual))->assertNotFound();
        }
        foreach (['accept', 'reject', 'complete'] as $action) {
            $this->patchJson('/api/v1/inquiries/'.$inquiry->id.'/'.$action, [], $this->bearer($dual))->assertNotFound();
        }
        $this->assertSame('requested', $reservation->fresh()->status->value);
    }

    public function test_options_require_accepted_published_schedule_and_current_participant_permissions(): void
    {
        [$client, $provider, $accepted] = $this->reservationContext();
        $pending = $this->inquiry($client);
        $this->getJson('/api/v1/inquiries/'.$pending->id.'/reservation-options', $this->bearer($client))->assertConflict();
        $this->postJson('/api/v1/inquiries/'.$pending->id.'/reservations', $this->reservationData(), $this->bearer($client))->assertConflict();
        $noSchedule = $this->inquiry($client, status: InquiryStatus::Accepted);
        $this->getJson('/api/v1/inquiries/'.$noSchedule->id.'/reservation-options', $this->bearer($client))->assertConflict();
        $accepted->service->update(['status' => 'paused']);
        $this->getJson('/api/v1/inquiries/'.$accepted->id.'/reservation-options', $this->bearer($client))->assertConflict();
        $accepted->service->update(['status' => 'published']);
        $provider->roles()->detach();
        $this->getJson('/api/v1/inquiries/'.$accepted->id.'/reservation-options', $this->bearer($client))->assertConflict();
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_options_and_proposal_observe_changes_of_revision_and_timezone(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $base = '/api/v1/inquiries/'.$inquiry->id;
        $old = $this->getJson($base.'/reservation-options', $this->bearer($client))->json('data');
        $this->publishSchedule($provider, 'America/New_York', 1);
        $new = $this->getJson($base.'/reservation-options', $this->bearer($client))->assertOk()->assertJsonPath('data.availability_revision', 2)->assertJsonPath('data.timezone', 'America/New_York');
        $this->postJson($base.'/reservations', $this->reservationData(['availability_revision' => $old['availability_revision']]), $this->bearer($client))->assertConflict();
        $this->postJson($base.'/reservations', $this->reservationData(['availability_revision' => 2]), $this->bearer($client))->assertCreated()->assertJsonPath('data.starts_at', '2030-01-07T14:00:00.000000Z');
    }

    public function test_invalid_past_blocked_and_out_of_horizon_slots_have_no_partial_writes(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->publishSchedule($provider, revision: 1, changes: ['blocked_dates' => ['2030-01-07']]);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/reservations';
        foreach ([['date' => '2029-12-31'], ['date' => '2030-01-07'], ['time' => '07:00']] as $slot) {
            $this->postJson($url, $this->reservationData($slot + ['availability_revision' => 2]), $this->bearer($client))->assertConflict();
        }
        foreach ([['date' => '2030-04-02'], ['date' => '2030-02-30'], ['time' => '24:00'], ['availability_revision' => []], ['client_user_id' => $client->id]] as $slot) {
            $this->postJson($url, $this->reservationData($slot + ['availability_revision' => 2]), $this->bearer($client))->assertUnprocessable();
        }
        $this->assertDatabaseCount('service_reservations', 0);
        $this->assertDatabaseCount('reservation_events', 0);
    }

    public function test_confirmed_overlaps_are_rejected_without_exposing_other_clients(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $other = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted);
        $reservation = $this->proposeReservation($other);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $response = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(), $this->bearer($client))->assertConflict();
        $this->assertStringNotContainsString($other->client->email, $response->getContent());
        $this->assertDatabaseCount('service_reservations', 1);
    }

    public function test_dst_gap_and_ambiguous_time_reuse_reservation_time_validation(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext('America/New_York');
        $this->publishSchedule($provider, 'America/New_York', 1, ['intervals' => [['day_of_week' => 7, 'start' => '00:00', 'end' => '05:00']]]);
        foreach ([['2030-03-10', '02:30', '2030-03-01'], ['2030-11-03', '01:30', '2030-10-25']] as [$date, $time, $today]) {
            $this->travelTo(\Carbon\Carbon::parse($today.' 12:00:00', 'UTC'));
            unset($this->mobileTokens[$client->id]);
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(['date' => $date, 'time' => $time, 'availability_revision' => 2]), $this->bearer($client))
                ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_confirmed_reservation_cancel_before_start_and_reject_cancel_after_start(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $url = '/api/v1/reservations/'.$reservation->id.'/cancel';
        $this->patchJson($url, ['version' => 1], $this->bearer($client))->assertConflict();
        $this->patchJson($url, ['version' => 2], $this->bearer($client))->assertOk()->assertJsonPath('data.version', 3);
        $other = $this->proposeReservation($inquiry, ['date' => '2030-01-08']);
        app(ReservationWorkflow::class)->decide($provider, $other->id, 'confirm', ['version' => 1]);
        $this->travelTo(\Carbon\Carbon::parse($other->starts_at_utc, 'UTC')->addMinute());
        $this->patchJson('/api/v1/reservations/'.$other->id.'/cancel', ['version' => 2], $this->bearer($client))->assertConflict();
    }

    public function test_reservation_json_filters_and_unknown_fields_are_strict(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $response = $this->getJson('/api/v1/reservations/'.$reservation->id, $this->bearer($client))->assertOk();
        foreach (['client_user_id', 'last_actor_user_id', 'last_reason', 'idempotency_key', 'availability_revision', 'provider_profile_id', 'email'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
        }
        foreach (['status=bad', 'per_page=51', 'user_id=1'] as $query) {
            $this->getJson('/api/v1/reservations?'.$query, $this->bearer($client))->assertUnprocessable();
        }
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/cancel', ['version' => 1, 'status' => 'completed'], $this->bearer($client))->assertUnprocessable();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/reservation-options?provider_id=1', $this->bearer($client))->assertUnprocessable();
    }

    public function test_failed_event_storage_rolls_back_proposal_and_returns_workflow_conflict(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        DB::unprepared("CREATE TRIGGER fail_mobile_reservation BEFORE INSERT ON reservation_events BEGIN SELECT RAISE(ABORT, 'PRIVATE STORAGE'); END");
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'activity_notifications']);
        $response = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(), $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'activity_notifications']));
    }

    public function test_schedule_storage_failure_does_not_return_available_options(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->mock(ProviderAvailability::class)->shouldReceive('publicFor')->once()->andReturn(['unavailable' => true]);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/reservation-options', $this->bearer($client))->assertStatus(503);
    }
}
