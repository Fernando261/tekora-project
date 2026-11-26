<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Exceptions\ReservationFailure;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\InquiryWorkflow;
use App\Services\ProviderAvailability;
use App\Services\ReservationTransactions;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BookingHoldTest extends LabTestCase
{
    private function at(int $micros): void
    {
        $instant = BookingClock::date($micros);
        $this->bookingFixtureClock($instant->format('Y-m-d H:i:s.u'));
        $this->travelTo($instant);
    }

    private function newScenario(?User $provider = null, ?string $date = null, string $time = '09:00', string $message = 'Solicitud B2 sintética', bool $propose = true): array
    {
        if ($date === null) {
            $floor = (int) DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
            $this->at(max(time() * 1000000, $floor) + 1_234_567);
            $date = BookingClock::date(BookingClock::micros())->addDays(2)->toDateString();
        }
        $provider ??= $this->account(RoleName::Provider);
        $client = $this->account(RoleName::Client);
        $service = $this->service($provider);
        if (! $service->providerProfile->availabilitySetting()->exists()) {
            app(ProviderAvailability::class)->save($provider, ['revision' => 0, 'timezone' => 'UTC', 'blocked_dates' => [],
                'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '08:00', 'end' => '19:00'], range(1, 7))]);
        }
        app(ServicePricing::class)->publish($provider, $service->id, ['amount_cents' => 100010, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, $message)['inquiry'];
        $inquiry = $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->accept($client, $quote->id);
        $input = ['date' => $date, 'time' => $time, 'availability_revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'quote_id' => $quote->id];
        $reservation = $propose ? app(ReservationWorkflow::class)->propose($client, $inquiry->id, $input)['reservation'] : null;

        return [$provider, $client, $service, $inquiry, $reservation, $input];
    }

    private function action(int $version = 1): array
    {
        return ['version' => $version, 'idempotency_key' => (string) Str::uuid()];
    }

    private function decision(User $user, ServiceReservation $reservation, string $action, ?array $input = null): ServiceReservation
    {
        return app(ReservationWorkflow::class)->decide($user, $reservation->id, $action, $input ?? $this->action());
    }

    private function rejects(callable $operation, int $status = 409): ReservationFailure
    {
        try {
            $operation();
            $this->fail('Expected the booking command to reject.');
        } catch (ReservationFailure $error) {
            $this->assertSame($status, $error->status);

            return $error;
        }
    }

    public function test_web_api_commercial_journey_completion_review_and_restart_marker(): void
    {
        $this->withoutVite();
        [$provider, $client, $service, $inquiry, , $proposal] = $this->newScenario(message: 'Marcador comercial V8.1.7', propose: false);
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('1,000.10');
        $token = $client->createToken('mobile:mysql-b2', ['client:read'], now()->addDay())->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $inquiryUrl = '/api/v1/inquiries/'.$inquiry->id;
        $this->getJson($inquiryUrl.'/reservation-options', $headers)->assertOk()
            ->assertJsonPath('data.commercial.accepted_quotes.0.id', $proposal['quote_id'])
            ->assertJsonPath('data.commercial.accepted_quotes.0.total_cents', 100010)
            ->assertJsonPath('data.commercial.accepted_quotes.0.price_version', 1)
            ->assertJsonPath('data.commercial.accepted_quotes.0.duration_minutes', 60);
        $id = $this->postJson($inquiryUrl.'/reservations', $proposal, $headers)->assertCreated()->json('data.id');
        $reservation = ServiceReservation::findOrFail($id);

        $holdKey = $this->action();
        $held = $this->decision($provider, $reservation, 'confirm', $holdKey);
        $this->assertSame(ReservationStatus::Held, $held->effectiveStatus());
        $expiry = $held->hold->expires_at_us;
        $this->assertSame(900_000_000, $expiry - $held->hold->accepted_at_us);
        $this->assertSame($expiry, $this->decision($provider, $reservation, 'confirm', $holdKey)->hold->expires_at_us);
        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))->assertOk();
        $this->actingAs($client, 'web')->get(route('reservations.show', $held))->assertOk();
        $this->get(route('quotes.show', $held->quote_id))->assertOk();
        $this->getJson(route('reservations.checkout-context', $held))->assertOk()->assertJsonPath('data.hold.state', 'held');
        auth()->forgetGuards();
        $url = '/api/v1/reservations/'.$held->id;
        $this->getJson($url.'/checkout-context', $headers)->assertOk()->assertJsonPath('data.hold.state', 'held')
            ->assertJsonPath('data.confirmation_policy.requires_verified_payment', false);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $headers)->assertOk();
        $this->getJson($url.'/demo-checkout', $headers)->assertNotFound();
        $other = $this->account(RoleName::Client);
        $otherToken = $other->createToken('mobile:mysql-other', ['client:read'], now()->addDay())->plainTextToken;
        auth()->forgetGuards();
        $this->getJson($url.'/checkout-context', ['Authorization' => 'Bearer '.$otherToken])->assertNotFound();
        auth()->forgetGuards();
        $decision = $this->action();
        $this->patchJson($url.'/commit', $decision, $headers)->assertOk();
        $confirmed = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertSame('committed', $confirmed->hold->state);
        $this->assertSame($confirmed->version, $this->decision($client, $confirmed, 'commit', $decision)->version);
        $this->assertDatabaseHas('activity_notifications', ['event' => 'reservation_confirmed', 'service_reservation_id' => $held->id, 'user_id' => $provider->id]);
        $this->rejects(fn () => $this->decision($provider, $confirmed, 'complete', $this->action(2)));

        $end = \Carbon\CarbonImmutable::parse($confirmed->ends_at_utc, 'UTC')->getTimestamp() * 1000000;
        $this->at($end);
        $completed = $this->decision($provider, $confirmed, 'complete', $this->action(2));
        $this->assertSame(ReservationStatus::Completed, $completed->status);
        $this->assertSame(InquiryStatus::Completed, app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete)->status);
        $review = app(ReviewWorkflow::class)->publish($client, $completed->id, ['rating' => 5, 'comment' => 'Recorrido B2 verificado'])['review'];
        $this->assertDatabaseHas('activity_notifications', ['event' => 'review_published', 'service_review_id' => $review->id, 'user_id' => $provider->id]);
        $this->assertSame(['held', 'committed'], $completed->hold->events()->orderBy('version')->pluck('to_state')->all());
        $this->assertSame(['proposal', 'confirm', 'complete'], $completed->events()->orderBy('version')->pluck('reason')->all());
    }

    public static function expiration(): iterable
    {
        yield 'one microsecond before' => [-1, true];
        yield 'exclusive boundary' => [0, false];
        yield 'one microsecond after' => [1, false];
    }

    #[DataProvider('expiration')]
    public function test_expiration_boundaries_and_expired_view_without_materialization(int $offset, bool $allowed): void
    {
        [$provider, $client, , $inquiry, $reservation, $proposal] = $this->newScenario();
        $held = $this->decision($provider, $reservation, 'confirm');
        $this->at($held->hold->expires_at_us + $offset);
        $this->assertSame('held', $held->fresh()->hold->state);
        $this->assertSame($allowed, DB::table('reservation_active_bookings')->where('reservation_id', $held->id)->exists());
        if ($allowed) {
            $this->assertSame('committed', $this->decision($client, $held, 'commit')->hold->state);

            return;
        }
        // SQL must also allow a replacement while the historical hold is still stored as held.
        if ($offset === 0) {
            $row = $held->getAttributes();
            unset($row['id']);
            $row['idempotency_key'] = (string) Str::uuid();
            $new = ServiceReservation::findOrFail(DB::table('service_reservations')->insertGetId($row));
            $this->assertSame('held', $held->fresh()->hold->state);
        }
        $this->rejects(fn () => $this->decision($client, $held, 'commit'));
        $this->assertSame('expired', $held->fresh()->hold->state);
        $this->assertSame(ReservationStatus::Requested, $held->fresh()->status);
        $new ??= app(ReservationWorkflow::class)->propose($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()] + $proposal)['reservation'];
        $this->assertNotSame($held->id, $new->id);
        $this->assertSame(['held', 'expired'], $held->hold->events()->orderBy('version')->pluck('to_state')->all());
    }

    public function test_receipts_are_immutable_and_cancellation_releases_the_slot_across_services(): void
    {
        [$provider, $client, , , $first, $proposal] = $this->newScenario();
        [$sameProvider, $other, , , $second] = $this->newScenario($provider, $proposal['date']);
        $key = $this->action();
        $first = $this->decision($provider, $first, 'confirm', $key);
        $this->assertSame($first->id, $this->decision($provider, $first, 'confirm', $key)->id);
        $this->rejects(fn () => $this->decision($provider, $second, 'confirm', $key));
        $this->rejects(fn () => $this->decision($provider, $second, 'confirm'));
        $this->assertNull($second->fresh()->hold);
        $this->assertMySqlError(1644, fn () => DB::table('reservation_holds')->where('service_reservation_id', $first->id)
            ->update(['state' => 'committed', 'version' => 2, 'closed_at_us' => $first->hold->accepted_at_us]), 'reservation_hold_transition');
        $this->assertMySqlError(1644, fn () => DB::table('reservation_action_receipts')->where('idempotency_key', $key['idempotency_key'])->delete(), 'reservation_hold_append_only');
        $this->assertMySqlError(1644, fn () => DB::table('service_reservations')->where('id', $first->id)->update(['quote_id' => null]), 'reservation_quote_immutable');

        $this->assertSame('released', $this->decision($client, $first, 'cancel')->hold->state);
        $this->assertSame('held', $this->decision($sameProvider, $second, 'confirm')->hold->state);
        $this->assertSame($other->id, $this->decision($other, $second, 'commit')->last_actor_user_id);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) n FROM reservation_occupancy a
            JOIN reservation_occupancy b ON a.reservation_id<b.reservation_id AND a.provider_profile_id=b.provider_profile_id
                AND a.starts_at_utc<b.ends_at_utc AND a.ends_at_utc>b.starts_at_utc')->n);
    }

    public function test_confirm_and_notice_roll_back_as_one_unit_and_remain_retryable(): void
    {
        [$provider, $client, , , $reservation] = $this->newScenario();
        $held = $this->decision($provider, $reservation, 'confirm');
        $key = $this->action();
        DB::unprepared("CREATE TRIGGER b2_test_notice_fault BEFORE INSERT ON activity_notifications FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic notice failure'");
        try {
            $this->rejects(fn () => $this->decision($client, $held, 'commit', $key), 503);
            $this->assertSame(ReservationStatus::Requested, $held->fresh()->status);
            $this->assertSame('held', $held->fresh()->hold->state);
            $this->assertSame(['held'], $held->hold->events()->pluck('to_state')->all());
            $this->assertSame(['proposal'], $held->events()->pluck('reason')->all());
            $this->assertDatabaseMissing('reservation_action_receipts', ['idempotency_key' => $key['idempotency_key']]);
            $this->assertDatabaseMissing('activity_notifications', ['service_reservation_id' => $held->id, 'event' => 'reservation_confirmed']);
        } finally {
            DB::unprepared('DROP TRIGGER b2_test_notice_fault');
        }
        $this->assertSame('committed', $this->decision($client, $held, 'commit', $key)->hold->state);
    }

    public function test_savepoint_reads_current_state_and_keeps_parent_ownership(): void
    {
        [$provider, $client, , , $reservation] = $this->newScenario();
        $store = app(ReservationTransactions::class);
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->rejects(fn () => $store->run(fn () => $this->fail('External PDO adopted.')), 503);
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }

        DB::beginTransaction();
        try {
            DB::table('cache')->insert(['key' => 'b2-outer', 'value' => 'owned by parent', 'expiration' => 0]);
            try {
                $store->run(function () {
                    DB::table('cache')->insert(['key' => 'b2-inner', 'value' => 'rolled back', 'expiration' => 0]);
                    throw new RuntimeException('synthetic');
                });
                $this->fail('Savepoint must roll back a structural failure.');
            } catch (RuntimeException $error) {
                $this->assertSame('synthetic', $error->getMessage());
            }
            $this->assertDatabaseMissing('cache', ['key' => 'b2-inner']);
            $this->assertDatabaseHas('cache', ['key' => 'b2-outer']);
            $this->assertSame('held', $this->decision($provider, $reservation, 'confirm')->hold->state);
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertNull($reservation->fresh()->hold);
        $this->assertDatabaseMissing('cache', ['key' => 'b2-outer']);
    }

    private function worker(array $input): array
    {
        return $this->startWorker('booking-worker.php', json_encode($input + ['time' => BookingClock::micros()], JSON_THROW_ON_ERROR));
    }

    private function dispatch(array $worker): int
    {
        fwrite($worker[1][0], "go\n");

        return $this->workerResult($worker)['connection'];
    }

    private function awaitQuery(int $id, string|array ...$needles): void
    {
        $until = hrtime(true) + 5_000_000_000;
        do {
            $process = collect(DB::select('SHOW FULL PROCESSLIST'))->firstWhere('Id', $id);
            if (in_array($process->Command ?? null, ['Query', 'Execute'], true)
                && array_all($needles, fn ($needle) => array_any((array) $needle, fn ($part) => str_contains(strtolower($process->Info ?? ''), $part)))) {
                $this->assertSame($id, (int) $process->Id);

                return;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        $this->fail('Worker never executed the expected SQL: '.json_encode($needles, JSON_THROW_ON_ERROR));
    }

    public static function isolations(): iterable
    {
        yield 'repeatable read' => ['REPEATABLE READ'];
        yield 'read committed' => ['READ COMMITTED'];
    }

    public static function expiryWaits(): iterable
    {
        foreach (self::isolations() as $label => [$isolation]) {
            yield "$label admission" => [$isolation, 'reservation_hold_clock'];
            yield "$label provider" => [$isolation, 'provider_profiles'];
        }
    }

    #[DataProvider('expiryWaits')]
    public function test_waiter_crossing_expiry_cannot_commit_with_an_older_snapshot(string $isolation, string $blockedTable): void
    {
        [$provider, $client, $service, , $reservation] = $this->newScenario();
        $held = $this->decision($provider, $reservation, 'confirm');
        $worker = $this->worker(['actor' => $client->id, 'action' => 'commit', 'reservation' => $held->id, 'body' => $this->action(),
            'after_wait' => $held->hold->expires_at_us, 'after_wait_on' => $blockedTable, 'isolation' => $isolation, 'parent' => true]);
        try {
            DB::transaction(function () use ($worker, $blockedTable, $service) {
                DB::table($blockedTable)->where('id', $blockedTable === 'reservation_hold_clock' ? 1 : $service->provider_profile_id)->lockForUpdate()->sole();
                $this->awaitQuery($this->dispatch($worker), $blockedTable, 'for update');
            });
            $result = $this->workerResult($worker);
            $this->assertSame(409, $result['status']);
            $this->assertTrue($result['physical']);
            $this->assertSame(1, $result['level']);
            $this->assertTrue($result['released']);
            $this->assertSame('expired', $held->fresh()->hold->state);
            $this->assertSame(ReservationStatus::Requested, $held->fresh()->status);
        } finally {
            $this->closeWorker($worker);
        }
    }

    #[DataProvider('isolations')]
    public function test_two_workers_compete_across_services_and_only_one_holds_the_same_slot(string $isolation): void
    {
        [$provider, , , , $first, $proposal] = $this->newScenario();
        [, , , , $second] = $this->newScenario($provider, $proposal['date']);
        $workers = [];
        try {
            foreach ([$first, $second] as $reservation) {
                $workers[] = $this->worker(['actor' => $provider->id, 'action' => 'confirm', 'reservation' => $reservation->id,
                    'body' => $this->action(), 'isolation' => $isolation, 'parent' => true]);
            }
            DB::transaction(function () use ($workers) {
                DB::table('reservation_hold_clock')->where('id', 1)->lockForUpdate()->sole();
                foreach ($workers as $worker) {
                    $this->awaitQuery($this->dispatch($worker), 'reservation_hold_clock', 'for update');
                }
            });
            $outcomes = array_map($this->workerResult(...), $workers);
            $this->assertEqualsCanonicalizing(['held', 409], array_map(fn ($result) => $result['state'] ?? $result['status'], $outcomes));
            $this->assertSame(1, DB::table('reservation_holds')->whereIn('service_reservation_id', [$first->id, $second->id])->count());
            $this->assertSame(1, DB::table('reservation_occupancy')->whereIn('reservation_id', [$first->id, $second->id])->count());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_lock_wait_during_admission_runs_no_callback(): void
    {
        $this->newScenario();
        $worker = $this->worker(['action' => 'admit', 'timeout' => 1]);
        try {
            DB::transaction(function () use ($worker) {
                DB::table('reservation_hold_clock')->where('id', 1)->lockForUpdate()->sole();
                $this->dispatch($worker);
                $this->assertSame(['status' => 409, 'native' => 1205, 'calls' => 0, 'physical' => false, 'level' => 0], $this->workerResult($worker));
            });
            $this->assertSame('retry', app(ReservationTransactions::class)->run(fn () => 'retry'));
        } finally {
            $this->closeWorker($worker);
        }
    }

    public static function reservationOwners(): iterable
    {
        yield 'adapter owns root' => [false];
        yield 'Laravel owns root' => [true];
    }

    #[DataProvider('reservationOwners')]
    public function test_real_deadlock_never_replays_callback_or_orphans_laravel_caller(bool $parent): void
    {
        $this->newScenario();
        $keys = array_map(fn ($n) => 'booking-deadlock-weight-'.$n, range(1, 20));
        DB::table('cache')->insert(array_map(fn ($key) => ['key' => $key, 'value' => 'seed', 'expiration' => 0], [...$keys, 'booking-deadlock-locked']));
        $worker = $this->worker(['action' => 'deadlock', 'parent' => $parent]);
        try {
            DB::transaction(function () use ($worker, $keys, $parent) {
                foreach ([...$keys, 'booking-deadlock-locked'] as $key) {
                    DB::table('cache')->where('key', $key)->update(['value' => 'survivor']);
                }
                $id = $this->dispatch($worker);
                $this->assertSame("locked\n", fgets($worker[1][1]));
                fwrite($worker[1][0], "cross\n");
                $this->awaitQuery($id, 'update `cache`');
                DB::table('reservation_hold_clock')->where('id', 1)->lockForUpdate()->sole();
                $this->assertSame(['status' => 409, 'native' => 1213, 'calls' => 1, 'physical' => false, 'level' => $parent ? 1 : 0]
                    + ($parent ? ['blocked_until_parent_ends' => true, 'released' => true] : []), $this->workerResult($worker));
            });
            $this->assertDatabaseMissing('cache', ['key' => 'booking-deadlock-inner-work']);
            $this->assertDatabaseMissing('cache', ['key' => 'booking-deadlock-parent-work']);
        } finally {
            $this->closeWorker($worker);
            DB::table('cache')->whereIn('key', [...$keys, 'booking-deadlock-locked'])->delete();
        }
    }

    #[DataProvider('isolations')]
    public function test_first_price_wins_against_unpriced_proposal_with_a_preexisting_snapshot(string $isolation): void
    {
        [$provider, , , , , $scheduled] = $this->newScenario();
        $service = $this->service($provider);
        $client = $this->account(RoleName::Client);
        $inquiry = app(InquiryWorkflow::class)->open($client, $service->id, 'Primera publicación concurrente')['inquiry'];
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Accept);
        $input = ['idempotency_key' => (string) Str::uuid()] + $scheduled;
        unset($input['quote_id']);
        $worker = $this->worker(['actor' => $client->id, 'action' => 'propose', 'inquiry' => $inquiry->id, 'body' => $input,
            'isolation' => $isolation, 'parent' => true]);
        try {
            app(PricingStore::class)->atomic(function () use ($worker, $provider, $service) {
                app(ServicePricing::class)->publish($provider, $service->id, ['amount_cents' => 100010,
                    'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
                $this->awaitQuery($this->dispatch($worker), 'pricing_write_lock', 'for update');
            });
            $this->assertSame(['quote_id'], $this->workerResult($worker)['validation']);
            $this->assertFalse($inquiry->reservations()->exists());
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_schedule_revision_can_win_before_proposal_and_roles_before_definitive_confirmation(): void
    {
        [$provider, $client, , $inquiry, $reservation, $proposal] = $this->newScenario();
        $held = $this->decision($provider, $reservation, 'confirm');
        $worker = $this->worker(['actor' => $client->id, 'action' => 'commit', 'reservation' => $held->id, 'body' => $this->action(), 'parent' => true]);
        try {
            DB::transaction(function () use ($client, $worker) {
                $client->roles()->detach();
                $this->awaitQuery($this->dispatch($worker), 'role_user', 'lock in share mode');
            });
            $this->assertSame(403, $this->workerResult($worker)['status']);
            $this->assertSame('held', $held->fresh()->hold->state);
        } finally {
            $this->closeWorker($worker);
        }
        $client->roles()->attach(\App\Models\Role::where('slug', RoleName::Client->value)->sole()->id);
        $this->decision($client, $held, 'cancel');
        $input = ['idempotency_key' => (string) Str::uuid()] + $proposal;
        $worker = $this->worker(['actor' => $client->id, 'action' => 'propose', 'inquiry' => $inquiry->id, 'body' => $input, 'parent' => true]);
        try {
            DB::transaction(function () use ($provider, $worker) {
                app(ProviderAvailability::class)->save($provider, ['revision' => 1, 'timezone' => 'UTC',
                    'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '08:00', 'end' => '19:00'], range(1, 7)), 'blocked_dates' => []]);
                $this->awaitQuery($this->dispatch($worker), 'provider_profiles', ['for update', 'lock in share mode']);
            });
            $this->assertSame(409, $this->workerResult($worker)['status']);
            $this->assertSame(1, $inquiry->reservations()->count());
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_confirmation_cancellation_and_inquiry_closure_compete_without_duplicate_histories(): void
    {
        [$provider, $client, , $inquiry, $reservation] = $this->newScenario();
        $held = $this->decision($provider, $reservation, 'confirm');
        $workers = [];
        try {
            foreach (['commit', 'cancel', 'close'] as $action) {
                $workers[] = $this->worker(['actor' => $action === 'close' ? $provider->id : $client->id, 'action' => $action,
                    'reservation' => $held->id, 'inquiry' => $inquiry->id, 'body' => $this->action(), 'parent' => true]);
            }
            DB::transaction(function () use ($workers) {
                DB::table('reservation_hold_clock')->where('id', 1)->lockForUpdate()->sole();
                foreach ($workers as $worker) {
                    $this->awaitQuery($this->dispatch($worker), 'reservation_hold_clock', 'for update');
                }
            });
            [$committed, $cancelled, $closed] = array_map($this->workerResult(...), $workers);
            $this->assertSame(409, $closed['status']);
            $this->assertSame(1, (int) isset($committed['state']) + (int) isset($cancelled['state']));
            $current = $held->fresh();
            $this->assertSame($committed['state'] ?? $cancelled['state'], $current->hold->state);
            $this->assertSame(2, $current->events()->count());
            $this->assertSame(2, $current->hold->events()->count());
            $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }
}
