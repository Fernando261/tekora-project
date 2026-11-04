<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Models\ActivityNotification;
use App\Models\InquiryNotification;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ActivityEventsTest extends TestCase
{
    use ReviewFixtures;

    public function test_proposal_confirmation_and_completion_notify_the_other_participant_once_per_version(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $notices = ActivityNotification::orderBy('id')->get();
        $this->assertSame(['reservation_requested', 'reservation_confirmed', 'reservation_completed'], $notices->pluck('event')->map->value->all());
        $this->assertSame([$provider->id, $client->id, $client->id], $notices->pluck('user_id')->all());
        $this->assertSame([1, 2, 3], $notices->pluck('reservation_version')->all());
        foreach ($notices as $notice) {
            $event = $reservation->events()->where('version', $notice->reservation_version)->sole();
            $this->assertNotSame($event->actor_user_id, $notice->user_id); $this->assertSame($event->occurred_at_utc, $notice->created_at);
            $this->assertNull($notice->service_review_id); $this->assertNull($notice->read_at);
        }
        $this->assertDatabaseCount('inquiry_notifications', 2);
    }

    public function test_rejection_and_cancellations_by_either_participant_use_the_correct_recipient(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $workflow = app(ReservationWorkflow::class);
        foreach (['reject', 'client_cancel', 'provider_cancel'] as $action) {
            $reservation = $this->proposeReservation($inquiry);
            $actor = $action === 'client_cancel' ? $client : $provider;
            $workflow->decide($actor, $reservation->id, $action === 'reject' ? 'reject' : 'cancel', ['version' => 1]);
            $notice = ActivityNotification::where('service_reservation_id', $reservation->id)->where('reservation_version', 2)->sole();
            $this->assertSame($action === 'client_cancel' ? $provider->id : $client->id, $notice->user_id);
            $this->assertNotSame($actor->id, $notice->user_id);
            $this->assertSame($action === 'reject' ? 'reservation_rejected' : 'reservation_cancelled', $notice->event->value);
        }
        $this->assertDatabaseCount('activity_notifications', 6);
    }

    public function test_closing_an_inquiry_cancels_its_proposal_and_emits_two_distinct_facts(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $notice = ActivityNotification::where('service_reservation_id', $reservation->id)->where('reservation_version', 2)->sole();
        $this->assertSame('reservation_cancelled', $notice->event->value); $this->assertSame($client->id, $notice->user_id);
        $this->assertSame('inquiry_completed', $reservation->events()->where('version', 2)->sole()->reason);
        $this->assertSame(1, InquiryNotification::where('user_id', $client->id)->where('event', 'completed')->count());
        $this->assertDatabaseCount('activity_notifications', 2); $this->assertDatabaseCount('inquiry_notifications', 3);
    }

    public function test_automatic_completion_uses_the_same_reservation_event_and_does_not_duplicate_it(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $this->assertSame(1, ActivityNotification::where('service_reservation_id', $reservation->id)->where('event', 'reservation_completed')->where('user_id', $client->id)->count());
        $this->assertSame(1, InquiryNotification::where('user_id', $client->id)->where('event', 'completed')->count());
        $this->assertDatabaseCount('activity_notifications', 3); $this->assertDatabaseCount('reservation_events', 3);
    }

    public function test_retries_reads_forms_expiration_and_failed_decisions_do_not_emit_notifications(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $data = $this->reservationData();
        $this->actingAs($client)->get(route('reservations.create', $inquiry))->assertOk(); $this->assertDatabaseCount('activity_notifications', 0);
        $reservation = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $data)['reservation'];
        $this->post(route('reservations.store', $inquiry), $data)->assertStatus(303);
        foreach ([$client, $provider] as $actor) $this->actingAs($actor)->get(route('reservations.show', $reservation))->assertOk();
        $this->actingAs($client)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertForbidden();
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 99])->assertStatus(409);
        $this->travelTo(Carbon::parse($reservation->starts_at_utc, 'UTC'));
        $this->get(route('reservations.show', $reservation))->assertOk()->assertSee('Propuesta expirada');
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->assertDatabaseCount('activity_notifications', 1);
    }

    public function test_review_publication_and_withdrawal_notify_provider_without_payload_or_duplicate_retries(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 1, 'COMENTARIO-PRIVADO-QUE-NO-VA-EN-AVISOS');
        app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 1, 'comment' => $review->comment]);
        app(ReviewWorkflow::class)->withdraw($client, $review->id); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $notices = ActivityNotification::where('service_review_id', $review->id)->orderBy('id')->get();
        $this->assertSame(['review_published', 'review_withdrawn'], $notices->pluck('event')->map->value->all());
        $this->assertSame([$provider->id, $provider->id], $notices->pluck('user_id')->all());
        foreach ($notices as $notice) {
            $this->assertNull($notice->service_reservation_id); $this->assertNull($notice->reservation_version);
            $this->assertNotSame($client->id, $notice->user_id);
            $this->assertStringNotContainsString($review->comment, $notice->toJson());
            $this->assertStringNotContainsString('rating', $notice->toJson());
        }
        $this->actingAs($provider)->get(route('notifications.index'))->assertOk()->assertSee('Tu servicio recibió una reseña verificada')->assertSee('Una reseña dejó de mostrarse públicamente')
            ->assertDontSee($review->comment)->assertDontSee($client->name)->assertDontSee($client->email)->assertDontSee('1.0 de 5 estrellas')->assertDontSee(route('reviews.show', $review));
        $this->assertDatabaseCount('activity_notifications', 5);
    }

    public function test_activity_storage_failure_rolls_back_reservation_creation_and_transition(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        DB::unprepared("CREATE TRIGGER activity_forced_failure BEFORE INSERT ON activity_notifications BEGIN SELECT RAISE(ABORT, 'private notice failure'); END");
        config(['app.debug' => true]);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409)->assertDontSee('SQLSTATE')->assertDontSee('private notice failure');
        $this->assertDatabaseCount('service_reservations', 0); $this->assertDatabaseCount('reservation_events', 0); $this->assertDatabaseCount('activity_notifications', 0);
        DB::unprepared('DROP TRIGGER activity_forced_failure'); $reservation = $this->proposeReservation($inquiry); $before = $reservation->getAttributes();
        DB::unprepared("CREATE TRIGGER activity_forced_failure BEFORE INSERT ON activity_notifications BEGIN SELECT RAISE(ABORT, 'private notice failure'); END");
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertDatabaseCount('reservation_events', 1); $this->assertDatabaseCount('activity_notifications', 1);
    }

    public function test_activity_storage_failure_rolls_back_review_publication_and_withdrawal(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        DB::unprepared("CREATE TRIGGER activity_forced_failure BEFORE INSERT ON activity_notifications BEGIN SELECT RAISE(ABORT, 'private notice failure'); END");
        $this->actingAs($client)->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(409);
        $this->assertDatabaseCount('service_reviews', 0); $this->assertDatabaseCount('activity_notifications', 3);
        DB::unprepared('DROP TRIGGER activity_forced_failure'); $review = $this->publishReview($reservation); $before = $review->getAttributes();
        DB::unprepared("CREATE TRIGGER activity_forced_failure BEFORE INSERT ON activity_notifications BEGIN SELECT RAISE(ABORT, 'private notice failure'); END");
        $this->patchJson(route('reviews.withdraw', $review))->assertStatus(409);
        $this->assertSame($before, $review->fresh()->getAttributes()); $this->assertDatabaseCount('activity_notifications', 4);
    }

    public function test_outer_rollback_and_old_inquiry_notification_failure_leave_no_orphan_activity(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        DB::beginTransaction(); $this->proposeReservation($inquiry); DB::rollBack();
        $this->assertDatabaseCount('activity_notifications', 0); $this->assertDatabaseCount('service_reservations', 0);
        $reservation = $this->proposeReservation($inquiry); $before = $reservation->getAttributes();
        InquiryNotification::creating(fn () => throw new \RuntimeException('simulated old notice failure'));
        try { app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete); $this->fail('El cierre debe ser atómico.'); }
        catch (\RuntimeException $exception) { $this->assertSame('simulated old notice failure', $exception->getMessage()); }
        finally { InquiryNotification::flushEventListeners(); }
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertDatabaseCount('activity_notifications', 1); $this->assertDatabaseCount('inquiry_notifications', 2);
    }
}
