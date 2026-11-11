<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use Tests\Support\Retired\Profesionista;
use Tests\Support\Retired\ProfesionistaDisponibilidad;
use App\Models\ServiceReservation;
use App\Services\InquiryMessaging;
use App\Services\InquiryReadTracking;
use App\Services\ReservationWorkflow;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\EnforcedCsrf;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationCsrfCompatibilityTest extends TestCase
{
    use ReservationFixtures;

    public function test_real_csrf_is_required_for_creation_and_every_decision(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->actingAs($client)->withSession(['_token' => 'reservation-csrf'])->get(route('reservations.create', $inquiry))->assertOk()->assertSee('name="_token"', false);
        $this->post(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(419);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['_token' => 'wrong']))->assertStatus(419);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['_token' => 'reservation-csrf']))->assertStatus(303);
        $reservation = ServiceReservation::sole();
        foreach (['confirm', 'reject', 'cancel', 'complete'] as $action) {
            $this->actingAs($provider)->patch(route('reservations.'.$action, $reservation), ['version' => 1])->assertStatus(419);
            $this->patch(route('reservations.'.$action, $reservation), ['version' => 1, '_token' => 'wrong'])->assertStatus(419);
        }
        $this->patch(route('reservations.confirm', $reservation), ['version' => 1, '_token' => 'reservation-csrf'])->assertStatus(303);
        $this->assertSame('confirmed', $reservation->fresh()->status->value); $this->assertDatabaseCount('reservation_events', 2);
    }

    public function test_reservation_writes_and_reading_do_not_change_inquiry_messages_notices_or_read_progress(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $key = (string) \Illuminate\Support\Str::uuid(); $message = $this->message($provider, $inquiry, 'Texto privado anterior a 6B.', $key);
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $tables = ['users', 'role_user', 'provider_profiles', 'service_inquiries', 'service_inquiry_messages', 'inquiry_notifications', 'service_inquiry_reads', 'provider_availability_settings', 'provider_weekly_intervals', 'provider_blocked_dates'];
        $before = $this->reservationRows($tables); $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        foreach ([$client, $provider] as $actor) {
            $this->actingAs($actor)->get(route('reservations.show', $reservation))->assertOk();
            $this->get(route('reservations.index'))->assertOk();
            $this->get(route('inquiries.show', $inquiry))->assertOk();
        }
        $this->assertSame($before, $this->reservationRows($tables));
        $retry = app(InquiryMessaging::class)->send($provider, $inquiry->id, 'Texto privado anterior a 6B.', $key);
        $this->assertFalse($retry['created']); $this->assertSame($message->id, $retry['message']->id);
        $this->assertSame(0, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status); $this->assertTrue($provider->hasRole(RoleName::Client));
        $this->get(route('availability.edit'))->assertOk();
        $this->get(route('catalog.show', $inquiry->service_id))->assertOk()->assertSee('Horarios orientativos, sujetos a confirmación con el prestador');
    }

    public function test_legacy_guard_and_same_email_never_authorize_or_supply_central_bookings(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $legacy = Profesionista::create(['nombre' => 'Profesional heredado', 'email' => $provider->email, 'cedula' => 'LEGACY-RESERVATION',
            'password' => Hash::make('separate-legacy-password'), 'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '23:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 11:00:00']);
        $tables = ['profesionistas', 'profesionista_disponibilidades', 'profesionista_bloqueos', 'citas']; $before = $this->reservationRows($tables);
        $this->actingAs($legacy, 'retired-fixture');
        $this->post('/login-profesionista')->assertNotFound();
        $this->get(route('reservations.index'))->assertRedirect('/login');
        $this->get(route('reservations.show', $reservation))->assertRedirect('/login');
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertUnauthorized();
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertUnauthorized();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->assertSame($before, $this->reservationRows($tables)); $this->assertGuest('web');
        $this->get('/profesionista/disponibilidad')->assertNotFound();
    }
}
