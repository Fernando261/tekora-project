<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\FaultPoint;
use Tests\Support\BookingOwnershipLab\NativeOutcome;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BusyConcurrencyTest extends LabTestCase
{
    public static function admissionScenarios(): array
    {
        return ['injected-before-native' => ['pre-native'], 'real-writer-release' => ['release'], 'real-writer-exhaustion' => ['exhaust'], 'injected-unknown-native' => ['uncertain']];
    }

    #[DataProvider('admissionScenarios')]
    public function test_t1_17_admission_busy_retries_only_after_safe_close(string $scenario): void
    {
        $fixture = $this->fixture('T1-17-'.$scenario);
        $holder = null;
        if (in_array($scenario, ['release', 'exhaust'], true)) {
            $holder = $this->startWorker($fixture, 'writer_lock');
            FaultPlan::await($fixture, 'ready-holder');
        }
        $faults = match ($scenario) {
            'pre-native' => new FaultPlan([FaultPoint::BEFORE_NATIVE_BEGIN->value => 'busy_once']),
            'uncertain' => new FaultPlan([FaultPoint::AFTER_NATIVE_BEFORE_WITNESS->value => 'busy_always']),
            'release' => (new FaultPlan)->releaseOnRetry($fixture, FaultPoint::BEFORE_NATIVE_BEGIN),
            default => new FaultPlan,
        };
        [$result, $factory] = $this->execute($fixture, $faults);
        if ($scenario === 'exhaust') {
            FaultPlan::signal($fixture, 'release');
        }
        if ($holder !== null) {
            self::assertTrue($this->finishWorker($holder)['rollback_proven']);
        }
        self::assertLessThanOrEqual(3, count($this->events($result, 'BEGIN_ATTEMPT')));
        if ($scenario === 'uncertain') {
            self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
            self::assertSame(0, $result->callbackCount);
            self::assertCount(1, $this->events($result, 'BEGIN_ATTEMPT'));
            self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
            $pdo = $factory->weakPdo();
            $connection = $factory->weakConnection();
            unset($factory);
            $this->assertRootCustody($pdo, $connection, $fixture);
        } else {
            $success = $scenario !== 'exhaust';
            self::assertSame($success ? NativeOutcome::COMMITTED : NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
            self::assertSame($success ? 1 : 0, $result->callbackCount);
            self::assertSame($success ? 2 : 3, count($this->events($result, 'BEGIN_ATTEMPT')));
            if ($success) {
                $this->assertCommittedSnapshot($fixture->observe('S'), 1);
            } else {
                $this->assertSnapshot($fixture->observe('S'), 0);
            }
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
        }
        foreach ($this->events($result, 'RETRY_SCHEDULED') as $event) {
            self::assertLessThanOrEqual(25_000, $event['wait_us']);
            self::assertLessThan($event['deadline_ns'], $event['time_ns']);
        }
    }

    public function test_t1_18_real_commit_busy_repeats_same_commit_without_callback_replay(): void
    {
        $fixture = $this->fixture('T1-18');
        $holder = $this->startWorker($fixture, 'reader_lock');
        FaultPlan::await($fixture, 'ready-holder');
        [$result, $factory] = $this->execute($fixture, (new FaultPlan)->releaseOnRetry($fixture, FaultPoint::BEFORE_NATIVE_COMMIT));
        self::assertTrue($this->finishWorker($holder)['rollback_proven']);
        self::assertSame(NativeOutcome::COMMITTED, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(1, $this->events($result, 'BEGIN_ATTEMPT'));
        self::assertCount(2, $this->events($result, 'COMMIT_ATTEMPT'));
        self::assertCount(1, $this->events($result, 'COMMIT_BUSY_ACTIVE'));
        self::assertCount(1, array_unique(array_column($this->events($result, 'COMMIT_REQUESTED'), 'generation')));
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        $this->assertCommittedSnapshot($fixture->observe('S'), 1);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function exhaustedCommit(): array
    {
        return ['known-rollback' => [false], 'lost-close-proof' => [true]];
    }

    #[DataProvider('exhaustedCommit')]
    public function test_t1_19_real_commit_busy_exhaustion_is_not_success_or_replay(bool $loseClose): void
    {
        $fixture = $this->fixture('T1-19');
        $holder = $this->startWorker($fixture, 'reader_lock');
        FaultPlan::await($fixture, 'ready-holder');
        [$result, $factory] = $this->execute($fixture, new FaultPlan($loseClose ? [FaultPoint::BEFORE_NATIVE_ROLLBACK->value => 'false'] : []));
        FaultPlan::signal($fixture, 'release');
        self::assertTrue($this->finishWorker($holder)['rollback_proven']);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(3, $this->events($result, 'COMMIT_ATTEMPT'));
        self::assertCount(1, $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertSame(5, \Tests\Support\BookingOwnershipLab\LabFailure::sqliteCode($result->primaryError));
        self::assertSame('CommitBusyExhausted', $result->primaryError->reason);
        if ($loseClose) {
            self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
            $pdo = $factory->weakPdo();
            $connection = $factory->weakConnection();
            unset($factory);
            $this->assertRootCustody($pdo, $connection, $fixture);
        } else {
            self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
            $this->assertSnapshot($fixture->observe('S'), 0);
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
        }
    }

    public static function uncertainCommits(): array
    {
        return [
            'false-injected' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'false'],
            'exception-injected' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'throw'],
            'physical-commit-ack-lost' => [FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER, 'throw'],
            'locked' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'locked'],
            'ioerr' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'ioerr'],
            'readonly' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'readonly'],
            'extended-busy' => [FaultPoint::BEFORE_NATIVE_COMMIT, 'extended_busy'],
        ];
    }

    #[DataProvider('uncertainCommits')]
    public function test_t1_20_uncertain_commit_never_rolls_back_or_replays(FaultPoint $point, string $kind): void
    {
        $fixture = $this->fixture('T1-20');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([$point->value => $kind]));
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame('UNKNOWN_LOCAL', $result->localStatus);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(1, $this->events($result, 'COMMIT_ATTEMPT'));
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertSame([], $this->events($result, 'COMMIT_CONFIRMED'));
        if (in_array($kind, ['locked', 'ioerr', 'readonly', 'extended_busy'], true)) {
            self::assertSame('CommitUncertain', $result->primaryError->reason);
        }
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public static function concurrentKeys(): array
    {
        return ['different-keys' => [false], 'same-key' => [true]];
    }

    #[DataProvider('concurrentKeys')]
    public function test_t1_30_process_barriers_force_contention_without_inheriting_pdo(bool $sameKey): void
    {
        $fixture = $this->initializedByWorker('T1-30');
        $left = $this->startWorker($fixture, 'write', 'left-probe', 'left');
        $right = $this->startWorker($fixture, 'write', $sameKey ? 'left-probe' : 'right-probe', 'right');
        FaultPlan::await($fixture, 'ready-left');
        FaultPlan::await($fixture, 'ready-right');
        FaultPlan::signal($fixture, 'go-left');
        FaultPlan::await($fixture, 'a-left');
        FaultPlan::signal($fixture, 'go-right');
        $a = $this->finishWorker($left);
        $b = $this->finishWorker($right);
        self::assertNotSame(getmypid(), $a['pid']);
        self::assertNotSame($a['pid'], $b['pid']);
        self::assertSame('COMMITTED', $a['result']['native_outcome']);
        self::assertSame($sameKey ? 'ROLLED_BACK' : 'COMMITTED', $b['result']['native_outcome']);
        self::assertSame(1, $a['result']['callback_count']);
        self::assertSame(1, $b['result']['callback_count']);
        self::assertSame('RELEASED', $a['release']['status']);
        self::assertSame('RELEASED', $b['release']['status']);
        $begin = array_filter($b['result']['trace'], fn ($row) => $row['event'] === 'BEGIN_ATTEMPT');
        self::assertGreaterThanOrEqual(2, count($begin));
        $confirmed = json_decode(file_get_contents($fixture->directory.'/committed-left.signal'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($a['pid'], $confirmed['pid']);
        $baseline = $a['baseline'];
        $this->assertCommittedSnapshot($baseline, 1);
        self::assertSame($a['pid'], $baseline['pid']);
        self::assertSame($a['result']['invocation_id'], $baseline['tables']['probe_receipt'][0]['invocation_id']);
        self::assertSame(1, (int) $baseline['tables']['coordination'][0]['admission_version']);
        self::assertLessThan($confirmed['time_ns'], $baseline['time_ns']);
        self::assertGreaterThan($confirmed['time_ns'], array_values($begin)[1]['time_ns']);
        $leftCommit = array_values(array_filter($a['result']['trace'], fn ($row) => $row['event'] === 'COMMIT_REQUESTED'))[0];
        $rightRollback = array_values(array_filter($b['result']['trace'], fn ($row) => $row['event'] === 'ROLLBACK_CONFIRMED'))[0];
        self::assertLessThan($leftCommit['time_ns'], array_values($begin)[0]['time_ns']);
        self::assertLessThan($leftCommit['time_ns'], $rightRollback['time_ns']);
        $observer = $this->finishWorker($this->startWorker($fixture, 'observe'));
        if ($sameKey) {
            $this->assertSnapshot($observer['snapshot'], 1, $baseline);
        } else {
            $this->assertCommittedSnapshot($observer['snapshot'], 2);
        }
        FaultPlan::assertNoInheritedHandles();
    }

    public static function fix2CoordinationChanges(): array
    {
        return ['version' => ['admission_version'], 'nonce' => ['owner_nonce']];
    }

    #[DataProvider('fix2CoordinationChanges')]
    public function test_fix2_nonempty_abort_requires_baseline_and_rejects_coordination_changes(string $column): void
    {
        $fixture = $this->fixture('T1-30-fix2-negative');
        [$committed, $firstFactory] = $this->execute($fixture, key: 'prior-commit');
        self::assertSame(NativeOutcome::COMMITTED, $committed->nativeOutcome);
        self::assertSame('RELEASED', $firstFactory->auditRelease()['status']);
        $baseline = $fixture->observe('S');
        $this->assertCommittedSnapshot($baseline, 1);
        [$aborted, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::AFTER_A->value => 'throw']), key: 'later-abort');
        self::assertSame(NativeOutcome::ROLLED_BACK, $aborted->nativeOutcome);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
        $snapshot = $fixture->observe('S');
        $this->assertSnapshot($snapshot, 1, $baseline);
        $missingRejected = false;
        try {
            $this->assertSnapshot($snapshot, 1);
        } catch (AssertionFailedError) {
            $missingRejected = true;
        }
        self::assertTrue($missingRejected, 'The initial empty baseline cannot certify this later abort.');
        // Diagnostic-snapshot negative control; do not bypass SQLite's guards.
        $changed = $snapshot;
        $changed['tables']['coordination'][0][$column] = $column === 'admission_version'
            ? (int) $changed['tables']['coordination'][0][$column] + 1
            : hash('sha256', 'synthetic-altered-nonce');
        $mutationRejected = false;
        try {
            $this->assertSnapshot($changed, 1, $baseline);
        } catch (AssertionFailedError) {
            $mutationRejected = true;
        }
        self::assertTrue($mutationRejected, 'A nonempty abort must preserve '.$column.'.');
        $fixture->record('release', ['baseline' => $baseline, 'actual_abort' => $snapshot, 'negative_snapshot' => $changed,
            'changed_column' => $column, 'missing_baseline_rejected' => $missingRejected, 'mutation_rejected' => $mutationRejected]);
    }

    public function test_fix2_final_snapshot_cannot_be_its_own_abort_baseline(): void
    {
        $fixture = $this->fixture('T1-30-fix2-self-baseline');
        [$committed, $factory] = $this->execute($fixture);
        self::assertSame(NativeOutcome::COMMITTED, $committed->nativeOutcome);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
        $snapshot = $fixture->observe('S');
        $this->assertCommittedSnapshot($snapshot, 1);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('A final snapshot cannot be its own baseline.');
        $this->assertSnapshot($snapshot, 1, $snapshot);
    }

    public function test_t1_31_fd_preflight_and_fresh_exec_are_enforced(): void
    {
        $fixture = $this->initializedByWorker('T1-31');
        $stream = fopen($fixture->path(), 'rb');
        $this->failure(fn () => FaultPlan::assertNoInheritedHandles(), 'InheritedHandleRejected');
        $this->failure(fn () => $this->startWorker($fixture, 'observe'), 'InheritedHandleRejected');
        fclose($stream);
        self::assertNotEmpty(FaultPlan::assertNoInheritedHandles());
        $rejected = $this->finishWorker($this->startWorker($fixture, 'unsupported'), 1);
        self::assertSame('WorkerArgumentsRejected', $rejected['error']['reason']);
        $observed = $this->finishWorker($this->startWorker($fixture, 'observe'));
        self::assertNotSame(getmypid(), $observed['pid']);
        self::assertSame(PHP_VERSION, $observed['php']);
        $this->assertSnapshot($observed['snapshot'], 0);
    }
}
