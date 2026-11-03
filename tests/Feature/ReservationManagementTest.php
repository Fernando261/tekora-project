<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationManagementTest extends TestCase
{
    use ReservationFixtures;

    public function test_client_proposes_and_both_participants_can_read_the_same_private_reservation(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->actingAs($client)->get(route('reservations.create', $inquiry))->assertOk()->assertSee('60 minutos')->assertSee('America/Mexico_City')->assertSee('name="idempotency_key"', false);
        $this->post(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(303);
        $reservation = ServiceReservation::sole();
        $this->assertSame([$inquiry->id, $inquiry->service_id, $provider->providerProfile->id, $client->id],
            [$reservation->service_inquiry_id, $reservation->service_id, $reservation->provider_profile_id, $reservation->client_user_id]);
        $this->assertSame('2030-01-07 15:00:00', $reservation->starts_at_utc); $this->assertSame('2030-01-07 16:00:00', $reservation->ends_at_utc);
        $this->assertSame(ReservationStatus::Requested, $reservation->status); $this->assertSame(1, $reservation->events()->count());
        $this->assertSame($client->id, $reservation->events()->sole()->actor_user_id);
        foreach ([$client, $provider] as $actor) {
            $this->actingAs($actor)->get(route('reservations.show', $reservation))->assertOk()->assertSee('No es una reserva confirmada')->assertSee('09:00–10:00')->assertDontSee($client->email)->assertDontSee('name="starts_at_utc"', false);
            $this->get(route('reservations.index'))->assertOk()->assertSee('Consultar reserva #'.$reservation->id);
            $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Reservas de esta solicitud');
        }
        $this->assertDatabaseCount('inquiry_notifications', 2); $this->assertDatabaseCount('citas', 0);
    }

    public function test_only_accepted_inquiries_allow_new_proposals(): void
    {
        [$client, $provider] = $this->reservationContext();
        foreach ([InquiryStatus::Pending, InquiryStatus::Rejected, InquiryStatus::Cancelled, InquiryStatus::Completed] as $status) {
            $inquiry = $this->inquiry($client, $provider, $status);
            $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
            $this->get(route('reservations.create', $inquiry))->assertStatus(409);
        }
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_outsiders_and_administrators_cannot_read_or_decide_private_reservations(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->get(route('reservations.index'))->assertRedirect('/login');
        foreach ([$this->account(RoleName::Client, RoleName::Provider), $this->account(RoleName::Admin)] as $stranger) {
            $this->actingAs($stranger)->get(route('reservations.show', $reservation))->assertForbidden();
            $this->get(route('reservations.create', $inquiry))->assertForbidden();
            $this->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertForbidden();
            foreach (['confirm', 'reject', 'cancel', 'complete'] as $action) $this->patchJson(route('reservations.'.$action, $reservation), ['version' => 1])->assertForbidden();
        }
        $this->get(route('reservations.show', 999999))->assertNotFound();
        $this->actingAs($client)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertForbidden();
        $this->actingAs($provider)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertForbidden();
        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
    }

    public function test_current_roles_are_required_and_dual_role_listing_only_contains_participating_reservations(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $received = $this->proposeReservation($inquiry);
        $otherProvider = $this->account(RoleName::Provider); $outgoingInquiry = $this->inquiry($provider, $otherProvider, InquiryStatus::Accepted);
        $this->publishSchedule($otherProvider); $outgoing = $this->proposeReservation($outgoingInquiry);
        $outsideInquiry = $this->inquiry($client, $otherProvider, InquiryStatus::Accepted); $outside = $this->proposeReservation($outsideInquiry);
        $this->actingAs($provider)->get(route('reservations.index'))->assertOk()->assertSee('Consultar reserva #'.$received->id)->assertSee('Consultar reserva #'.$outgoing->id)->assertDontSee('Consultar reserva #'.$outside->id);
        $provider->roles()->detach($provider->roles()->where('slug', 'provider')->value('roles.id'));
        $this->get(route('reservations.show', $received))->assertForbidden();
        $this->patchJson(route('reservations.confirm', $received), ['version' => 1])->assertForbidden();
        $this->get(route('reservations.show', $outgoing))->assertOk();
        $client->roles()->detach();
        $this->actingAs($client)->get(route('reservations.show', $received))->assertForbidden();
    }

    public function test_unknown_and_internal_fields_are_rejected_before_any_write(): void
    {
        [$client, , $inquiry] = $this->reservationContext(); $this->actingAs($client);
        $fields = ['client_user_id', 'provider_profile_id', 'service_id', 'service_inquiry_id', 'status', 'price', 'confirmed_at', 'starts_at_utc', 'ends_at_utc', 'timezone', 'last_actor_user_id', 'last_reason', 'version'];
        // Invalid attempts are checked directly through the service so throttling does not hide validation coverage.
        foreach ($fields as $field) {
            try { app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData([$field => 1])); $this->fail('Campo interno no permitido.'); }
            catch (\Illuminate\Validation\ValidationException $exception) { $this->assertArrayHasKey('form', $exception->errors()); }
        }
        $this->postJson(route('reservations.store', $inquiry), $this->reservationData(['status' => 'confirmed']))->assertUnprocessable();
        foreach ([['date' => '2030-02-30'], ['time' => '24:00'], ['time' => '9:00'], ['date' => ['invalid']], ['idempotency_key' => 'fake']] as $bad) {
            try { app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData($bad)); $this->fail('Formato no permitido.'); }
            catch (\Illuminate\Validation\ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
        }
        $this->assertDatabaseCount('service_reservations', 0); $this->assertDatabaseCount('reservation_events', 0);
    }

    public function test_rejection_is_terminal_and_a_new_proposal_keeps_the_original_history(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->actingAs($provider)->patch(route('reservations.reject', $reservation), ['version' => 1])->assertStatus(303);
        $this->assertSame(ReservationStatus::Rejected, $reservation->fresh()->status);
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 2])->assertStatus(409);
        $this->actingAs($client)->post(route('reservations.store', $inquiry), $this->reservationData(['time' => '11:00']))->assertStatus(303);
        $this->assertDatabaseCount('service_reservations', 2); $this->assertSame(['requested', 'rejected'], $reservation->events()->orderBy('version')->pluck('to_status')->all());
        $this->assertSame([$client->id, $provider->id], $reservation->events()->orderBy('version')->pluck('actor_user_id')->all());
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
    }

    public function test_confirmation_stale_forms_cancellation_and_completion_obey_temporal_rules(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->actingAs($provider)->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->patchJson(route('reservations.reject', $reservation), ['version' => 1])->assertStatus(409);
        $this->patchJson(route('reservations.complete', $reservation), ['version' => 2])->assertStatus(409);
        $this->actingAs($client)->patch(route('reservations.cancel', $reservation), ['version' => 2])->assertStatus(303);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 3])->assertStatus(409);
        $second = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $second->id, 'confirm', ['version' => 1]);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-07 15:30:00', 'UTC'));
        $this->actingAs($client)->patchJson(route('reservations.cancel', $second), ['version' => 2])->assertStatus(409);
        $this->actingAs($provider)->patchJson(route('reservations.complete', $second), ['version' => 2])->assertStatus(409);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-07 16:00:00', 'UTC'));
        $this->patch(route('reservations.complete', $second), ['version' => 2])->assertStatus(303);
        $this->assertSame(ReservationStatus::Completed, $second->fresh()->status);
        $this->assertDatabaseCount('reservation_events', 6); $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
    }

    public function test_expired_pending_proposals_can_be_closed_but_cannot_be_confirmed(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-07 15:00:00', 'UTC'));
        $this->actingAs($provider)->get(route('reservations.show', $reservation))->assertOk()->assertSee('Propuesta expirada')->assertDontSee('>Confirmar reserva<', false);
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-08']))->assertStatus(409);
        $this->patch(route('reservations.cancel', $reservation), ['version' => 1])->assertStatus(303);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-08']))->assertStatus(303);
        $this->assertDatabaseCount('service_reservations', 2);
    }

    public function test_creation_and_decision_rate_limits_are_independent_and_return_retry_after(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        for ($i = 0; $i < 5; $i++) $this->actingAs($client)->postJson(route('reservations.store', $inquiry), [])->assertUnprocessable();
        $this->postJson(route('reservations.store', $inquiry), [])->assertStatus(429)->assertHeader('Retry-After')->assertDontSee('SQLSTATE');
        $reservation = $this->proposeReservation($inquiry);
        for ($i = 0; $i < 15; $i++) $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), [])->assertUnprocessable();
        $this->patchJson(route('reservations.confirm', $reservation), [])->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs($client)->patch(route('reservations.cancel', $reservation), ['version' => 1])->assertStatus(303);
    }

    public function test_every_decision_rejects_manipulated_fields_and_missing_versions(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        foreach (['confirm', 'reject', 'cancel', 'complete'] as $action) {
            $this->actingAs($provider)->patchJson(route('reservations.'.$action, $reservation), ['version' => 1, 'status' => 'completed'])->assertUnprocessable();
            $this->patchJson(route('reservations.'.$action, $reservation), [])->assertUnprocessable();
        }
        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status); $this->assertDatabaseCount('reservation_events', 1);
    }

    public function test_empty_provider_listing_and_error_pages_only_link_to_authorized_destinations(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $provider->roles()->detach($provider->roles()->where('slug', 'client')->value('roles.id'));
        $this->actingAs($provider)->get(route('reservations.index'))->assertOk()
            ->assertSee('href="'.route('inquiries.received').'"', false)->assertDontSee('href="'.route('inquiries.index').'"', false);
        $this->get(route('inquiries.received'))->assertOk();
        $reservation = $this->proposeReservation($inquiry);
        $this->actingAs($this->account(RoleName::Admin))->get(route('reservations.show', $reservation))->assertForbidden()
            ->assertSee('Volver a mi cuenta')->assertDontSee('href="'.route('reservations.index').'"', false);
    }
}
