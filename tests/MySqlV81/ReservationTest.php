<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\ReservationTime;
use App\Services\ReservationTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ReservationTest extends LabTestCase
{
    public function test_identity_initial_state_permissions_and_unported_workflow_guard(): void
    {
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $client = $this->account(RoleName::Client);
        $inquiry = $workflow->open($client, $service->id, 'Reserva sintética pendiente')['inquiry'];
        $row = $this->reservationRow($inquiry);
        $this->assertMySqlError(1644, fn () => $this->reservations()->insert($row), 'reservation_inquiry_closed');
        $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        $other = $this->account(RoleName::Client, RoleName::Provider);
        $otherService = $this->service($other);
        foreach ([['service_inquiry_id' => PHP_INT_MAX], ['service_id' => $otherService->id],
            ['provider_profile_id' => $otherService->provider_profile_id],
            ['client_user_id' => $other->id, 'last_actor_user_id' => $other->id]] as $change) {
            $this->assertMySqlError(1644, fn () => $this->reservations()->insert($change + $row), 'reservation_identity');
        }
        $selfInquiry = ServiceInquiry::findOrFail(DB::table('service_inquiries')->insertGetId([
            'service_id' => $service->id, 'client_user_id' => $provider->id, 'status' => 'accepted',
            'service_title' => $service->title, 'message' => 'Identidad sintética no permitida',
        ]));
        $this->assertFalse(Gate::forUser($provider)->allows('propose', [ServiceReservation::class, $selfInquiry]));
        $this->assertMySqlError(1644, fn () => $this->reservations()->insert($this->reservationRow($selfInquiry)), 'reservation_identity');
        foreach ([['status' => 'confirmed'], ['last_reason' => 'confirm'], ['last_actor_user_id' => $provider->id], ['version' => 2]] as $change) {
            $this->assertMySqlError(1644, fn () => $this->reservations()->insert($change + $row), 'reservation_initial_state');
        }
        $id = $this->reservations()->insertGetId($row);
        $reservation = ServiceReservation::findOrFail($id);
        $this->assertArrayNotHasKey('active_slot', $reservation->getAttributes());
        $this->assertTrue(Gate::forUser($client)->allows('propose', [ServiceReservation::class, $inquiry]));
        $this->assertTrue(Gate::forUser($client)->allows('cancel', $reservation));
        $this->assertFalse(Gate::forUser($client)->allows('confirm', $reservation));
        $this->assertTrue(Gate::forUser($provider)->allows('confirm', $reservation));
        $this->assertFalse(Gate::forUser($other)->allows('view', $reservation));
        $this->assertFalse(Gate::forUser($other)->allows('confirm', $reservation));

        $this->assertSame(['provider_profiles', 'service_inquiries', 'service_quotes', 'services', 'users', 'users'], DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'service_reservations')->whereNotNull('REFERENCED_TABLE_NAME')
            ->orderBy('REFERENCED_TABLE_NAME')->pluck('REFERENCED_TABLE_NAME')->all());
        $this->assertSame(['service_reservations', 'users'], DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'reservation_events')->whereNotNull('REFERENCED_TABLE_NAME')
            ->orderBy('REFERENCED_TABLE_NAME')->pluck('REFERENCED_TABLE_NAME')->all());
        $this->assertMySqlError(1451, fn () => $client->delete());

        $this->assertSame('admitted', app(ReservationTransactions::class)->run(fn () => 'admitted'));
        $this->assertFalse(DB::table('reservation_holds')->where('service_reservation_id', $id)->exists());
    }

    public function test_transition_matrix_versions_and_event_immutability(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $row = $this->reservationRow($inquiry);
        $id = $this->reservations()->insertGetId($row);
        $confirm = $this->decision('confirmed', 'confirm', $provider->id);
        foreach ([['version' => 3], ['last_actor_user_id' => $inquiry->client_user_id], ['last_reason' => 'reject'],
            ['updated_at' => '2029-12-31 00:00:00'], ['updated_at' => $row['starts_at_utc']]] as $change) {
            $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)->update($change + $confirm), 'reservation_transition');
        }
        $this->assertSame(1, $this->reservations()->where('id', $id)->update($confirm));
        $complete = $this->decision('completed', 'complete', $provider->id, 3, $row['ends_at_utc']);
        $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)
            ->update(['updated_at' => $row['starts_at_utc']] + $complete), 'reservation_transition');
        $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)->update(
            $this->decision('cancelled', 'cancel', $inquiry->client_user_id, 3, $row['starts_at_utc'])), 'reservation_transition');
        $this->bookingFixtureClock($row['ends_at_utc']);
        try {
            $this->assertSame(1, $this->reservations()->where('id', $id)->update($complete));
        } finally {
            $this->bookingFixtureClock(null);
        }
        $events = $this->events($id);
        $this->assertSame([1, 2, 3], $events->map(fn ($event) => (int) $event->version)->all());
        $this->assertSame([null, 'requested', 'confirmed'], $events->pluck('from_status')->all());
        $this->assertSame(['requested', 'confirmed', 'completed'], $events->pluck('to_status')->all());
        $this->assertSame([$inquiry->client_user_id, $provider->id, $provider->id], $events->pluck('actor_user_id')->all());
        $this->assertSame([$row['updated_at'], $confirm['updated_at'], $row['ends_at_utc']], $events->pluck('occurred_at_utc')->all());
        $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)->update(['local_start_minute' => 700]), 'reservation_immutable');
        $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)->delete(), 'reservation_history_required');
        $last = (array) $events->last();
        unset($last['id']);
        foreach ([['from_status' => 'requested'], ['actor_user_id' => $inquiry->client_user_id], ['occurred_at_utc' => $row['starts_at_utc']]] as $change) {
            $this->assertMySqlError(1644, fn () => DB::table('reservation_events')->insert($change + $last), 'reservation_event_inconsistent');
        }
        $this->assertMySqlError(1062, fn () => DB::table('reservation_events')->insert($last));
        $this->assertMySqlError(1644, fn () => DB::table('reservation_events')->where('service_reservation_id', $id)->update(['reason' => 'cancel']), 'reservation_event_immutable');
        $this->assertMySqlError(1644, fn () => DB::table('reservation_events')->where('service_reservation_id', $id)->delete(), 'reservation_event_immutable');
        $this->assertEquals($events->all(), $this->events($id)->all());
    }

    public function test_parent_identity_guards_survive_terminal_history(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $id = $this->reservations()->insertGetId($this->reservationRow($inquiry));
        $this->assertMySqlError(1644, fn () => DB::table('service_inquiries')->where('id', $inquiry->id)->update(['status' => 'completed']), 'reservation_active_inquiry');
        $this->reservations()->where('id', $id)->update($this->decision('cancelled', 'inquiry_completed', $provider->id));
        $this->assertSame(1, DB::table('service_inquiries')->where('id', $inquiry->id)->update(['status' => 'completed']));
        $other = $this->account(RoleName::Client, RoleName::Provider);
        $otherService = $this->service($other);
        foreach ([['service_inquiries', $inquiry->id, ['client_user_id' => $other->id]],
            ['service_inquiries', $inquiry->id, ['service_id' => $otherService->id]],
            ['services', $service->id, ['provider_profile_id' => $otherService->provider_profile_id]],
            ['provider_profiles', $service->provider_profile_id, ['user_id' => $this->account(RoleName::Provider)->id]]] as [$table, $parent, $patch]) {
            $this->assertMySqlError(1644, fn () => DB::table($table)->where('id', $parent)->update($patch), 'reservation_identity_immutable');
        }
        $this->assertSame(['proposal', 'inquiry_completed'], $this->events($id)->pluck('reason')->all());
    }

    public function test_canonical_time_storage_fractional_rejection_local_day_and_dst(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $row = $this->reservationRow($inquiry, '01:15', '2030-01-07', 'Asia/Tokyo');
        $this->assertSame('2030-01-06 16:15:00', $row['starts_at_utc']);
        $this->assertSame('2030-01-07', $row['local_date']);
        foreach ([['local_start_minute' => 1380], ['local_start_minute' => '1.25'], ['availability_revision' => '1.25'],
            ['local_date' => '2030-02-30'], ['ends_at_utc' => '2030-01-06 17:16:00'], ['starts_at_utc' => '2030-01-06T16:15:00'],
            ['updated_at' => '2030-02-30 12:00:00'], ['idempotency_key' => str_repeat('A', 36)]] as $patch) {
            $this->assertMySqlError(3819, fn () => $this->reservations()->insert($patch + $row));
        }
        $this->assertMySqlError(1406, fn () => $this->reservations()->insert(['starts_at_utc' => '2030-01-06 16:15:00+00:00'] + $row));
        $this->assertFalse($this->reservations()->where('service_inquiry_id', $inquiry->id)->exists());
        DB::statement("SET SESSION time_zone = '+09:00'");
        try {
            $id = $this->reservations()->insertGetId($row);
            $this->assertSame('2030-01-06 16:15:00', $this->reservations()->where('id', $id)->value('starts_at_utc'));
            $this->assertSame('2030-01-07', $this->reservations()->where('id', $id)->value('local_date'));
        } finally {
            DB::statement("SET SESSION time_zone = '+00:00'");
        }
        $clock = app(ReservationTime::class);
        foreach ([['2030-03-10', '02:30'], ['2030-11-03', '01:30'], ['2030-01-02', '23:00']] as [$date, $time]) {
            try {
                $clock->resolve($date, $time, 'America/New_York');
                $this->fail('Expected invalid civil interval.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('time', $exception->errors());
            }
        }
        $this->assertSame(1379, $clock->resolve('2030-01-02', '22:59', 'America/New_York')['local_start_minute']);
    }

    public function test_reservation_and_event_rollback_and_safe_retry_after_known_failure(): void
    {
        [$provider, $service] = $this->providerService();
        $row = $this->reservationRow($this->acceptedInquiry($service, $provider));
        try {
            DB::transaction(function () use ($row) {
                $id = $this->reservations()->insertGetId($row);
                $this->assertCount(1, $this->events($id));
                throw new RuntimeException('Abort before commit');
            });
            $this->fail('Expected the transaction to abort.');
        } catch (RuntimeException $error) {
            $this->assertSame('Abort before commit', $error->getMessage());
        }
        $this->assertFalse($this->reservations()->where('service_inquiry_id', $row['service_inquiry_id'])->exists());
        $id = $this->reservations()->insertGetId($row);
        $confirm = $this->decision('confirmed', 'confirm', $provider->id);
        DB::unprepared("CREATE TRIGGER v813_synthetic_history_failure BEFORE INSERT ON reservation_events
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_history_failure'");
        try {
            $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $id)->update($confirm), 'synthetic_history_failure');
            $this->assertSame('requested', $this->reservations()->where('id', $id)->value('status'));
            $this->assertSame(['proposal'], $this->events($id)->pluck('reason')->all());
        } finally {
            DB::unprepared('DROP TRIGGER v813_synthetic_history_failure');
        }
        $this->assertSame(1, $this->reservations()->where('id', $id)->update($confirm));
        $this->assertSame(['proposal', 'confirm'], $this->events($id)->pluck('reason')->all());
    }

    public function test_parallel_proposals_and_idempotency_after_terminal_state(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $row = $this->reservationRow($inquiry);
        $duplicate = $this->reservationRow($inquiry, '10:00');
        $this->assertWaitThenReject('insert', ['profile' => $service->provider_profile_id, 'visible_status' => 'requested', 'row' => $duplicate],
            fn () => $this->reservations()->insert($row), 1644, 'reservation_one_active_inquiry');
        $persisted = $this->reservations()->where('service_inquiry_id', $inquiry->id)->sole();
        $this->assertSame($row['idempotency_key'], $persisted->idempotency_key);
        $this->reservations()->where('id', $persisted->id)->update($this->decision('rejected', 'reject', $provider->id));
        $this->assertMySqlError(1062, fn () => $this->reservations()->insert($row));
        $this->assertSame($persisted->id, $this->reservations()->where('client_user_id', $inquiry->client_user_id)
            ->where('service_inquiry_id', $inquiry->id)->where('idempotency_key', $row['idempotency_key'])->value('id'));
        $this->assertSame(['proposal', 'reject'], $this->events($persisted->id)->pluck('reason')->all());
        $new = $this->reservations()->insertGetId($duplicate);
        $this->assertSame(['proposal'], $this->events($new)->pluck('reason')->all());
    }

    public function test_concurrent_confirmation_uses_current_occupancy_and_permits_adjacent_slots(): void
    {
        [$provider, $service] = $this->providerService();
        $secondService = $this->service($provider, ['title' => 'Segundo servicio del mismo prestador']);
        $first = $this->reservations()->insertGetId($this->reservationRow($this->acceptedInquiry($service, $provider)));
        $sameSlot = $this->reservations()->insertGetId($this->reservationRow($this->acceptedInquiry($secondService, $provider)));
        $confirm = $this->decision('confirmed', 'confirm', $provider->id);
        $this->assertWaitThenReject('overlap', ['profile' => $service->provider_profile_id, 'visible_status' => 'confirmed', 'id' => $sameSlot, 'patch' => $confirm],
            fn () => $this->reservations()->where('id', $first)->update($confirm), 1644, 'reservation_overlap');
        $this->assertSame('requested', $this->reservations()->where('id', $sameSlot)->value('status'));
        $this->assertSame(['proposal'], $this->events($sameSlot)->pluck('reason')->all());
        $partial = $this->reservations()->insertGetId($this->reservationRow($this->acceptedInquiry($service, $provider), '09:30'));
        $this->assertMySqlError(1644, fn () => $this->reservations()->where('id', $partial)->update($confirm), 'reservation_overlap');
        $adjacent = $this->reservations()->insertGetId($this->reservationRow($this->acceptedInquiry($service, $provider), '10:00'));
        $this->assertSame(1, $this->reservations()->where('id', $adjacent)->update($confirm));
        $this->assertSame([$first, $adjacent], $this->reservations()->where('provider_profile_id', $service->provider_profile_id)
            ->where('status', 'confirmed')->orderBy('starts_at_utc')->pluck('id')->all());
        $this->assertSame(['proposal', 'confirm'], $this->events($adjacent)->pluck('reason')->all());

        [$otherProvider, $otherService] = $this->providerService();
        $otherSlot = $this->reservations()->insertGetId($this->reservationRow($this->acceptedInquiry($otherService, $otherProvider)));
        $this->assertSame(1, $this->reservations()->where('id', $otherSlot)->update($this->decision('confirmed', 'confirm', $otherProvider->id)));
    }

    public function test_inquiry_cannot_close_while_a_proposal_commits(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $row = $this->reservationRow($inquiry);
        $this->assertWaitThenReject('close', ['profile' => $service->provider_profile_id, 'visible_status' => 'requested', 'id' => $inquiry->id],
            fn () => $this->reservations()->insert($row), 1644, 'reservation_active_inquiry');
        $this->assertSame('accepted', $inquiry->fresh()->status->value);
        $this->assertSame('requested', $this->reservations()->where('service_inquiry_id', $inquiry->id)->sole()->status);

        $anotherInquiry = $this->acceptedInquiry($service, $provider);
        $this->assertWaitThenReject('insert', ['profile' => $service->provider_profile_id, 'visible_status' => 'confirmed',
            'row' => $this->reservationRow($anotherInquiry)],
            fn () => DB::table('service_inquiries')->where('id', $anotherInquiry->id)->update(['status' => 'completed']),
            1644, 'reservation_inquiry_closed');
        $this->assertSame('completed', $anotherInquiry->fresh()->status->value);
        $this->assertFalse($this->reservations()->where('service_inquiry_id', $anotherInquiry->id)->exists());
    }

    public function test_two_processes_consume_the_same_version_at_most_once(): void
    {
        [$provider, $service] = $this->providerService();
        $inquiry = $this->acceptedInquiry($service, $provider);
        $id = $this->reservations()->insertGetId($this->reservationRow($inquiry));
        $decisions = [$this->decision('confirmed', 'confirm', $provider->id), $this->decision('cancelled', 'cancel', $inquiry->client_user_id)];
        $workers = [];
        try {
            foreach ($decisions as $patch) {
                $workers[] = $this->reservationWorker('version', ['id' => $id, 'patch' => $patch]);
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            $results = array_map($this->workerResult(...), $workers);
            $this->assertEqualsCanonicalizing([['changed' => 0], ['changed' => 1]], $results);
            $winner = $decisions[array_search(['changed' => 1], $results, true)];
            $this->assertSame($winner['status'], $this->reservations()->where('id', $id)->value('status'));
            $this->assertSame(['proposal', $winner['last_reason']], $this->events($id)->pluck('reason')->all());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_deadlock_retries_reservation_and_history_together(): void
    {
        [$firstProvider, $firstService] = $this->providerService();
        [$secondProvider, $secondService] = $this->providerService();
        $rows = [$this->reservationRow($this->acceptedInquiry($firstService, $firstProvider)),
            $this->reservationRow($this->acceptedInquiry($secondService, $secondProvider))];
        $workers = [];
        try {
            foreach ($rows as $index => $row) {
                $workers[] = $this->reservationWorker('deadlock', ['row' => $row, 'other_profile' => $rows[1 - $index]['provider_profile_id']]);
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("locked\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "cross\n");
            }
            $results = array_map($this->workerResult(...), $workers);
            $this->assertEqualsCanonicalizing([['attempts' => 1, 'diagnostics' => []], ['attempts' => 2, 'diagnostics' => [1213]]], $results);
            foreach ($rows as $row) {
                $id = $this->reservations()->where('service_inquiry_id', $row['service_inquiry_id'])->sole()->id;
                $this->assertSame(['proposal'], $this->events($id)->pluck('reason')->all());
            }
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    private function assertWaitThenReject(string $mode, array $input, callable $operation, int $code, ?string $diagnostic): void
    {
        $worker = $this->reservationWorker($mode, $input);
        try {
            DB::transaction(function () use ($worker, $operation) {
                $operation();
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['code' => 1205, 'diagnostic' => null, 'snapshot' => 0, 'visible' => 0], $this->workerResult($worker));
            });
            fwrite($worker[1][0], "retry\n");
            $this->assertSame(['code' => $code, 'diagnostic' => $diagnostic, 'snapshot' => 0, 'visible' => 0], $this->workerResult($worker));
        } finally {
            $this->closeWorker($worker);
        }
    }

    private function reservationWorker(string $mode, array $input): array
    {
        return $this->startWorker('reservation-worker.php', $mode, json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function providerService(): array
    {
        $provider = $this->account(RoleName::Provider);

        return [$provider, $this->service($provider)];
    }

    private function acceptedInquiry(Service $service, User $provider): ServiceInquiry
    {
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($this->account(RoleName::Client), $service->id, 'Reserva sintética V8.1.3')['inquiry'];

        return $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
    }

    private function reservations(): \Illuminate\Database\Query\Builder
    {
        return DB::table('service_reservations');
    }

    private function events(int $id): \Illuminate\Support\Collection
    {
        return DB::table('reservation_events')->where('service_reservation_id', $id)->orderBy('version')->get();
    }

    private function decision(string $status, string $reason, int $actor, int $version = 2, string $at = '2030-01-02 12:00:00'): array
    {
        return ['status' => $status, 'version' => $version, 'last_actor_user_id' => $actor, 'last_reason' => $reason, 'updated_at' => $at];
    }
}
