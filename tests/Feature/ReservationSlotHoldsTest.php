<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Exceptions\ReservationFailure;
use App\Models\ServiceReservation;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationSlotHoldsTest extends TestCase
{
    use ReservationFixtures;

    private function context(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $price = app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->accept($client, $quote->id);
        $reservation = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData(['quote_id' => $quote->id]))['reservation'];

        return [$client, $provider, $inquiry, $quote, $reservation, $price];
    }

    private function input(array $changes = []): array
    {
        return $changes + ['version' => 1, 'idempotency_key' => (string) Str::uuid()];
    }

    private function held(array $changes = []): array
    {
        $context = $this->context();
        $context[4] = app(ReservationWorkflow::class)->decide($context[1], $context[4]->id, 'confirm', $this->input($changes));

        return $context;
    }

    public static function terminalNavigation(): array
    {
        return ['expired' => ['expired'], 'released' => ['released']];
    }

    #[DataProvider('terminalNavigation')]
    public function test_terminal_hold_shows_reproposal_link_only_to_its_client(string $state): void
    {
        [$client, $provider, $inquiry, , $reservation] = $this->held();
        if ($state === 'expired') {
            $this->travel(900)->seconds();
        } else {
            app(ReservationWorkflow::class)->decide($client, $reservation->id, 'cancel', $this->input());
        }
        $link = 'href="'.route('reservations.create', $inquiry).'"';
        $this->actingAs($client)->get(route('reservations.show', $reservation))->assertOk()
            ->assertSee($state === 'expired' ? 'Retención vencida' : 'Retención liberada')->assertSee($link, false);
        $this->actingAs($provider)->get(route('reservations.show', $reservation))->assertOk()->assertDontSee($link, false);
        $this->assertSame($state, $reservation->fresh()->hold->state);
        $this->integrity();
    }

    private function integrity(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM reservation_occupancy a JOIN reservation_occupancy b ON a.reservation_id<b.reservation_id AND a.provider_profile_id=b.provider_profile_id AND a.starts_at_utc<b.ends_at_utc AND a.ends_at_utc>b.starts_at_utc')->n);
    }

    public function test_only_accepting_a_concrete_proposal_starts_exactly_900_seconds(): void
    {
        [$client, $provider, $inquiry, $quote, $reservation] = $this->context();
        $this->assertDatabaseCount('reservation_holds', 0);
        $accepted = BookingClock::micros();
        $held = app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->input());
        $this->assertSame(ReservationStatus::Held, $held->effectiveStatus());
        $this->assertSame(ReservationStatus::Requested, $held->status);
        $this->assertSame($accepted, $held->hold->accepted_at_us);
        $this->assertSame($accepted + 900000000, $held->hold->expires_at_us);
        $this->assertSame($quote->id, $held->quote_id);
        $this->assertSame(900, $held->hold->remainingSeconds());
        $this->assertDatabaseCount('reservation_hold_events', 1);
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->integrity();
    }

    public static function boundary(): array
    {
        return ['899 seconds' => [899, true], 'exactly 900' => [900, false], '901 seconds' => [901, false]];
    }

    #[DataProvider('boundary')]
    public function test_server_boundary_and_no_partial_conversion(int $seconds, bool $allowed): void
    {
        [$client, , , , $reservation] = $this->held();
        $expires = $reservation->hold->expires_at_us;
        $this->travel($seconds)->seconds();
        try {
            $result = app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $this->input());
            $this->assertTrue($allowed);
            $this->assertSame(ReservationStatus::Confirmed, $result->status);
            $this->assertSame('committed', $result->hold->state);
            $this->assertSame($client->id, $result->last_actor_user_id);
        } catch (ReservationFailure $failure) {
            $this->assertFalse($allowed);
            $this->assertSame(409, $failure->status);
            $this->assertSame('expired', $reservation->fresh()->hold->state);
            $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
        }
        $this->assertSame($expires, $reservation->fresh()->hold->expires_at_us);
        $this->integrity();
    }

    public function test_same_acceptance_key_survives_reload_other_device_and_expiry_without_renewal(): void
    {
        $input = $this->input();
        [$client, $provider, , , $reservation] = $this->held($input);
        $original = (array) DB::table('reservation_holds')->first();
        $this->travel(450)->seconds();
        $this->actingAs($client)->get(route('reservations.show', $reservation))->assertOk()->assertSee('Horario reservado temporalmente');
        $retry = app(ReservationWorkflow::class)->decide($provider->fresh(), $reservation->id, 'confirm', $input);
        $this->assertSame(450, $retry->hold->remainingSeconds());
        $this->assertSame($original, (array) DB::table('reservation_holds')->first());
        $this->travel(450)->seconds();
        $retry = app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $input);
        $this->assertSame('expired', $retry->hold->state);
        $this->assertSame($original['accepted_at_us'], $retry->hold->accepted_at_us);
        $this->assertSame($original['expires_at_us'], $retry->hold->expires_at_us);
        $this->assertDatabaseCount('reservation_holds', 1);
        $this->assertDatabaseCount('reservation_action_receipts', 1);
        $this->assertDatabaseCount('reservation_hold_events', 2);
        $this->assertNull(DB::table('reservation_hold_events')->where('to_state', 'expired')->value('actor_user_id'));
        $this->integrity();
    }

    public function test_idempotency_key_with_different_content_is_rejected(): void
    {
        $input = $this->input();
        [, $provider, , , $reservation] = $this->held($input);
        $before = $this->reservationRows(['reservation_holds', 'reservation_hold_events', 'reservation_action_receipts']);
        try {
            app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'cancel', $input);
            $this->fail('Clave reutilizada con otra intención.');
        } catch (ReservationFailure $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame($before, $this->reservationRows(['reservation_holds', 'reservation_hold_events', 'reservation_action_receipts']));
        $this->integrity();
    }

    public function test_client_commit_is_idempotent_and_notifies_provider_with_no_occupation_gap(): void
    {
        [$client, $provider, , , $reservation] = $this->held();
        $input = $this->input();
        $result = app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $input);
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'activity_notifications', 'reservation_action_receipts']);
        $this->travel(901)->seconds();
        $retry = app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $input);
        $this->assertSame($result->version, $retry->version);
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'activity_notifications', 'reservation_action_receipts']));
        $this->assertDatabaseHas('activity_notifications', ['user_id' => $provider->id, 'service_reservation_id' => $reservation->id, 'event' => 'reservation_confirmed']);
        $this->assertSame(1, DB::table('reservation_occupancy')->count());
        $this->integrity();
    }

    public function test_another_service_of_same_provider_and_legacy_confirmation_cannot_bypass_hold(): void
    {
        [$client, $provider, , , $one] = $this->held();
        $otherInquiry = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted);
        // A competing proposal is created before holding the slot to exercise the old writer.
        $attributes = $one->getAttributes();
        unset($attributes['id']);
        $attributes = array_replace($attributes, ['service_inquiry_id' => $otherInquiry->id, 'service_id' => $otherInquiry->service_id, 'client_user_id' => $otherInquiry->client_user_id, 'quote_id' => null, 'idempotency_key' => (string) Str::uuid(), 'last_actor_user_id' => $otherInquiry->client_user_id]);
        DB::table('service_reservations')->insert($attributes);
        $two = ServiceReservation::where('service_inquiry_id', $otherInquiry->id)->sole();
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $two), ['version' => 1])->assertConflict();
        try {
            DB::table('service_reservations')->where('id', $two->id)->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id, 'last_reason' => 'confirm']);
            $this->fail('SQL directo debe rechazar solape.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame(ReservationStatus::Requested, $two->fresh()->status);
        $this->assertNotSame($one->service_id, $two->service_id);
        $this->integrity();
    }

    public function test_release_allows_another_proposal_but_old_hold_never_reactivates(): void
    {
        [$client, $provider, $inquiry, $quote, $one] = $this->held();
        $input = $this->input();
        $cancelled = app(ReservationWorkflow::class)->decide($client, $one->id, 'cancel', $input);
        $this->assertSame('released', $cancelled->hold->state);
        $two = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData(['quote_id' => $quote->id]))['reservation'];
        $two = app(ReservationWorkflow::class)->decide($provider, $two->id, 'confirm', $this->input());
        $this->assertSame(ReservationStatus::Held, $two->effectiveStatus());
        app(ReservationWorkflow::class)->decide($client, $one->id, 'cancel', $input);
        $this->assertSame('released', $one->fresh()->hold->state);
        $this->assertSame(1, DB::table('reservation_occupancy')->count());
        $this->integrity();
    }

    public function test_expiration_allows_new_proposal_and_old_confirmation_cannot_displace_it(): void
    {
        [$client, $provider, $inquiry, $quote, $one] = $this->held();
        $this->travel(900)->seconds();
        $two = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData(['quote_id' => $quote->id]))['reservation'];
        $two = app(ReservationWorkflow::class)->decide($provider, $two->id, 'confirm', $this->input());
        try {
            app(ReservationWorkflow::class)->decide($client, $one->id, 'commit', $this->input());
            $this->fail('No confirmar retención vencida.');
        } catch (ReservationFailure $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame('expired', $one->fresh()->hold->state);
        $this->assertSame('held', $two->fresh()->hold->state);
        $this->integrity();
    }

    public function test_later_price_and_schedule_edits_do_not_change_accepted_quote_or_held_slot(): void
    {
        [$client, $provider, , $quote, $reservation] = $this->held();
        $snapshot = (array) DB::table('service_quotes')->where('id', $quote->id)->first();
        app(ServicePricing::class)->publish($provider, $reservation->service_id, ['amount_cents' => 200000, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->publishSchedule($provider, 'America/Mexico_City', 1, ['intervals' => []]);
        $confirmed = app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $this->input());
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertSame($quote->id, $confirmed->quote_id);
        $this->assertSame($snapshot, (array) DB::table('service_quotes')->where('id', $quote->id)->first());
        $this->integrity();
    }

    public function test_foreign_quote_missing_quote_and_changed_quote_on_retry_are_rejected(): void
    {
        [$client, $provider, $inquiry, $quote, $reservation] = $this->context();
        $otherClient = $this->account(RoleName::Client);
        $otherInquiry = $this->inquiry($otherClient, $provider, InquiryStatus::Accepted);
        $input = $this->reservationData(['quote_id' => $quote->id]);
        $this->actingAs($otherClient)->postJson(route('reservations.store', $otherInquiry), $input)->assertUnprocessable();
        $this->assertDatabaseCount('service_reservations', 1);
        $this->actingAs($provider)->patchJson(route('reservations.commit', $reservation), $this->input())->assertForbidden();
        $this->actingAs($client)->patchJson(route('reservations.confirm', $reservation), $this->input())->assertForbidden();
        $this->integrity();
    }

    public function test_failure_after_hold_insert_rolls_back_hold_history_receipt_and_occupancy(): void
    {
        [, $provider, , , $reservation] = $this->context();
        DB::unprepared("CREATE TRIGGER injected_receipt_failure BEFORE INSERT ON reservation_action_receipts BEGIN SELECT RAISE(ABORT,'injected'); END");
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_action_receipts', 'activity_notifications', 'reservation_hold_clock']);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), $this->input())->assertConflict();
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_action_receipts', 'activity_notifications', 'reservation_hold_clock']));
        $this->assertSame(0, DB::table('reservation_occupancy')->count());
        $this->integrity();
    }

    public function test_hold_is_not_performed_service_and_cannot_close_inquiry_or_publish_review(): void
    {
        [$client, $provider, $inquiry, , $reservation] = $this->held();
        $this->actingAs($provider)->patchJson(route('inquiries.complete', $inquiry))->assertConflict();
        $this->patchJson(route('reservations.complete', $reservation), $this->input())->assertConflict();
        $this->actingAs($client)->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertConflict();
        $this->assertDatabaseCount('service_reviews', 0);
        $this->assertSame('held', $reservation->fresh()->hold->state);
        $this->integrity();
    }

    public function test_clock_rollback_cannot_reactivate_an_expired_hold(): void
    {
        [$client, , , , $reservation] = $this->held();
        $accepted = BookingClock::date($reservation->hold->accepted_at_us);
        $this->travel(900)->seconds();
        app(ReservationWorkflow::class)->refreshExpirations();
        $this->travelTo($accepted->addSeconds(100));
        $this->actingAs($client)->patchJson(route('reservations.commit', $reservation), $this->input())->assertStatus(503);
        $this->assertSame('expired', $reservation->fresh()->hold->state);
        $this->assertSame(ReservationStatus::HoldExpired, $reservation->fresh()->effectiveStatus());
        $this->integrity();
    }
}
