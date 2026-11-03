<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Models\InquiryNotification;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationInquiryLifecycleTest extends TestCase
{
    use ReservationFixtures;

    public function test_completing_inquiry_cancels_pending_proposal_in_the_same_transaction_with_actor_history(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->actingAs($provider)->patch(route('inquiries.complete', $inquiry))->assertStatus(303);
        $this->assertSame(InquiryStatus::Completed, $inquiry->fresh()->status);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $event = $reservation->events()->orderByDesc('version')->first();
        $this->assertSame('inquiry_completed', $event->reason); $this->assertSame($provider->id, $event->actor_user_id);
        $this->assertSame('completed', InquiryNotification::orderByDesc('id')->first()->event->value);
        $this->assertSame(0, $inquiry->reservations()->active()->count());
    }

    public function test_future_or_ongoing_confirmed_reservation_prevents_inquiry_completion_until_it_ends(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->actingAs($provider)->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-07 15:30:00', 'UTC'));
        $this->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status); $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertDatabaseCount('inquiry_notifications', 2); $this->assertDatabaseCount('reservation_events', 2);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-07 16:00:00', 'UTC'));
        $this->patch(route('inquiries.complete', $inquiry))->assertStatus(303);
        $this->assertSame(InquiryStatus::Completed, $inquiry->fresh()->status); $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertSame('inquiry_completed', $reservation->events()->orderByDesc('version')->first()->reason);
        $this->assertDatabaseCount('inquiry_notifications', 3);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
    }

    public function test_existing_inquiry_cancel_rules_are_preserved_and_booking_can_be_cancelled_separately(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $this->actingAs($client)->patchJson(route('inquiries.cancel', $inquiry))->assertStatus(409);
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->patch(route('reservations.cancel', $reservation), ['version' => 1])->assertStatus(303);
        $this->actingAs($provider)->patch(route('inquiries.complete', $inquiry))->assertStatus(303);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
    }

    public function test_notification_failure_rolls_back_inquiry_closure_and_its_reservation_event(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry); $before = $reservation->getAttributes();
        InquiryNotification::creating(fn () => throw new \RuntimeException('simulated notice failure'));
        try { app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete); $this->fail('Fallo de aviso debe revertir toda la operación.'); }
        catch (\RuntimeException $exception) { $this->assertSame('simulated notice failure', $exception->getMessage()); }
        finally { InquiryNotification::flushEventListeners(); }
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->assertDatabaseCount('reservation_events', 1); $this->assertDatabaseCount('inquiry_notifications', 2);
    }

    public function test_database_prevents_raw_inquiry_closure_with_active_reservation(): void
    {
        [, , $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        foreach (['cancelled', 'completed', 'rejected', 'pending'] as $status) {
            try { DB::table('service_inquiries')->where('id', $inquiry->id)->update(['status' => $status]); $this->fail('No dejar reservas activas huérfanas.'); }
            catch (\Illuminate\Database\QueryException $exception) { $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status); }
        }
        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
    }
}
