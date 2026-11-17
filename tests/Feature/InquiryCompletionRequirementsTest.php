<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Exceptions\InquiryConflict;
use App\Exceptions\ReservationFailure;
use App\Models\InquiryNotification;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class InquiryCompletionRequirementsTest extends TestCase
{
    use ReservationFixtures;

    private const TABLES = ['service_inquiries', 'service_reservations', 'reservation_events', 'inquiry_notifications', 'activity_notifications'];

    public static function blockedStates(): array
    {
        return array_map(fn ($state) => [$state], ['none', 'requested', 'rejected', 'cancelled', 'future', 'elapsed', 'legacy_automatic']);
    }

    #[DataProvider('blockedStates')]
    public function test_stale_or_manipulated_completion_requests_never_decide_a_reservation_or_emit_notices(string $state): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $state === 'none' ? null : $this->proposeReservation($inquiry);
        $workflow = app(ReservationWorkflow::class);
        if (in_array($state, ['rejected', 'cancelled'], true)) {
            $workflow->decide($state === 'rejected' ? $provider : $client, $reservation->id, $state === 'rejected' ? 'reject' : 'cancel', ['version' => 1]);
        }
        if (in_array($state, ['future', 'elapsed', 'legacy_automatic'], true)) {
            $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        }
        if (in_array($state, ['elapsed', 'legacy_automatic'], true)) {
            $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        }
        if ($state === 'legacy_automatic') {
            // Valid historical transition under the old schema; it is not explicit performance.
            DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'completed', 'version' => 3,
                'last_reason' => 'inquiry_completed', 'last_actor_user_id' => $provider->id, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
        }
        $before = $this->reservationRows(self::TABLES);
        $this->actingAs($provider)->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409)->assertJsonStructure(['message', 'inquiry_url']);
        $this->assertSame($before, $this->reservationRows(self::TABLES));
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->assertNotNull($workflow->inquiryCompletionBlocker($inquiry));
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('>Finalizar solicitud<', false);
    }

    public function test_explicit_authorized_performance_allows_completion_but_a_replay_does_not_duplicate_it(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $before = $this->reservationRows(['service_reservations', 'reservation_events']);
        $this->actingAs($provider)->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('>Finalizar solicitud<', false);
        $this->patch(route('inquiries.complete', $inquiry))->assertStatus(303);
        $this->assertSame(InquiryStatus::Completed, $inquiry->fresh()->status);
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events']));
        $this->assertDatabaseCount('inquiry_notifications', 3);
        $this->assertSame('complete', $reservation->events()->where('version', 3)->sole()->reason);
        $finished = $this->reservationRows(self::TABLES);
        $this->travel(1)->minutes();
        $this->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->assertSame($finished, $this->reservationRows(self::TABLES));
    }

    public function test_a_completed_reservation_does_not_hide_a_new_active_proposal(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $new = $this->proposeReservation($inquiry, ['date' => '2030-01-08']);
        $before = $this->reservationRows(self::TABLES);
        $this->actingAs($provider)->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->assertSame($before, $this->reservationRows(self::TABLES));
        $this->assertSame(ReservationStatus::Requested, $new->fresh()->status);
    }

    public static function unauthorizedActors(): array
    {
        return [['client'], ['other_provider'], ['revoked_provider']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_ownership_and_current_permissions_still_apply_after_performance(string $kind): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $actor = match ($kind) {
            'client' => $client, 'other_provider' => $this->account(RoleName::Provider), default => $provider
        };
        if ($kind === 'revoked_provider') {
            $provider->roles()->detach();
        }
        $before = $this->reservationRows(self::TABLES);
        $this->actingAs($actor)->patchJson(route('inquiries.complete', $inquiry))->assertForbidden();
        $this->assertSame($before, $this->reservationRows(self::TABLES));
    }

    public function test_a_clock_earlier_than_the_performed_reservation_end_cannot_authorize_closure(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $this->travelBack();
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC')->subSecond());
        $before = $this->reservationRows(self::TABLES);
        $this->actingAs($provider)->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->assertSame($before, $this->reservationRows(self::TABLES));
    }

    public function test_notification_failure_rolls_back_only_the_attempted_inquiry_closure(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        $before = $this->reservationRows(self::TABLES);
        InquiryNotification::creating(fn () => throw new \RuntimeException('completion notice failure'));
        try {
            app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
            $this->fail('La operación debe revertirse si falla la notificación.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('completion notice failure', $exception->getMessage());
        } finally {
            InquiryNotification::flushEventListeners();
        }
        $this->assertSame($before, $this->reservationRows(self::TABLES));
    }

    #[DataProvider('writeOrder')]
    public function test_overlapping_proposal_and_closure_are_serialized_without_partial_effects(bool $proposalFirst): void
    {
        $uri = 'file:tekora-completion-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['closure_a', 'closure_b'] as $name) {
            config(['database.connections.'.$name => [
                'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('closure_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'closure_a', '--force' => true]));
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        DB::setDefaultConnection('closure_b');
        $clientB = User::findOrFail($client->id);
        $providerB = User::findOrFail($provider->id);
        $this->assertNotSame(DB::connection('closure_a')->getPdo(), DB::connection('closure_b')->getPdo());
        DB::setDefaultConnection('closure_a');
        DB::beginTransaction();
        if ($proposalFirst) {
            $next = $this->proposeReservation($inquiry, ['date' => '2030-01-08']);
        } else {
            app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        }
        DB::setDefaultConnection('closure_b');
        try {
            if ($proposalFirst) {
                app(InquiryWorkflow::class)->decide($providerB, $inquiry->id, InquiryAction::Complete);
            } else {
                app(ReservationWorkflow::class)->propose($clientB, $inquiry->id, $this->reservationData(['date' => '2030-01-08']));
            }
            $this->fail('La segunda operación no debe atravesar el bloqueo de escritura.');
        } catch (InquiryConflict|ReservationFailure $exception) {
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        } finally {
            DB::connection('closure_a')->commit();
        }
        $before = $this->reservationRows(self::TABLES);
        if ($proposalFirst) {
            $this->actingAs($providerB)->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        } else {
            $this->actingAs($clientB)->postJson(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-08']))->assertStatus(409);
        }
        $this->assertSame($before, $this->reservationRows(self::TABLES));
        $this->assertSame($proposalFirst ? InquiryStatus::Accepted : InquiryStatus::Completed, $inquiry->fresh()->status);
        $this->assertDatabaseCount('service_reservations', $proposalFirst ? 2 : 1);
    }

    public static function writeOrder(): array
    {
        return [[true], [false]];
    }

    public function test_historical_fixture_keeps_the_callers_clock_and_has_explicit_performance(): void
    {
        [, , $inquiry] = $this->reservationContext();
        $clock = now('UTC')->format('Y-m-d H:i:s');
        $reservation = $this->performInquiryReservation($inquiry);
        $this->assertSame($clock, now('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame(ReservationStatus::Completed, $reservation->status);
        $this->assertSame('complete', $reservation->events()->where('version', 3)->sole()->reason);
        $this->assertNull(app(ReservationWorkflow::class)->inquiryCompletionBlocker($inquiry));
    }
}
