<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Support\BookingOwnershipLab\FakePortA;
use Tests\Support\BookingOwnershipLab\FakePortB;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\FaultPoint;
use Tests\Support\BookingOwnershipLab\InvocationSpec;
use Tests\Support\BookingOwnershipLab\LabFailure;
use Tests\Support\BookingOwnershipLab\NativeOutcome;
use Tests\Support\BookingOwnershipLab\OwnershipCoordinator;
use Tests\Support\BookingOwnershipLab\PrivateConnectionFactory;
use Tests\Support\BookingOwnershipLab\ProbeOperation;
use Tests\Support\BookingOwnershipLab\ProbeOutbox;
use Tests\Support\BookingOwnershipLab\SessionLease;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OwnershipLifecycleTest extends LabTestCase
{
    public function test_t1_04_failure_before_native_begin_is_not_started(): void
    {
        $fixture = $this->fixture('T1-04');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::BEFORE_NATIVE_BEGIN->value => 'throw']));
        self::assertSame(NativeOutcome::NOT_STARTED, $result->nativeOutcome);
        self::assertSame(0, $result->callbackCount);
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertSame([], $this->events($result, 'NATIVE_STARTED'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function witnessedWindows(): array
    {
        return ['before-level' => [FaultPoint::AFTER_WITNESS_BEFORE_LEVEL, 0], 'after-level' => [FaultPoint::AFTER_LEVEL_BEFORE_WRITE, 1]];
    }

    #[DataProvider('witnessedWindows')]
    public function test_t1_05_witnessed_begin_failure_closes_at_level_zero_or_one(FaultPoint $point, int $level): void
    {
        $fixture = $this->fixture('T1-05');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([$point->value => 'throw']));
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame(0, $result->callbackCount);
        self::assertSame($level, $this->events($result, 'ROLLBACK_REQUESTED')[0]['laravel_level']);
        self::assertCount(1, $this->events($result, 'ROLLBACK_CONFIRMED'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function returnOrUnwind(): array
    {
        return ['return' => [false], 'unwind' => [true]];
    }

    #[DataProvider('returnOrUnwind')]
    public function test_t1_06_uncertain_start_is_rooted_after_return_or_unwind(bool $unwind): void
    {
        $fixture = $this->fixture('T1-06');
        if ($unwind) {
            mkdir($fixture->directory.'/runs.jsonl', 0700);
        }
        $factory = new PrivateConnectionFactory($fixture, new FaultPlan([FaultPoint::AFTER_NATIVE_BEFORE_WITNESS->value => 'throw']));
        $coordinator = $factory->coordinator();
        $caught = null;
        try {
            $result = $coordinator->run(new InvocationSpec('uncertain-start', $fixture->epoch()), OwnershipCoordinator::program(...));
        } catch (LabFailure $error) {
            $caught = $error;
            $result = $error->result;
        }
        self::assertSame($unwind, $caught !== null);
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame(0, $result->callbackCount);
        self::assertSame('retained_quarantine', $result->cleanupState);
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory, $coordinator);
        $this->assertRootCustody($pdo, $connection, $fixture);
        if ($caught !== null) {
            self::assertSame('EvidenceFailure', $caught->reason);
            $fixture->record('release', ['expected_evidence_failure' => $caught->jsonSerialize(), 'preserved_result' => $result]);
        }
    }

    public static function foreignModes(): array
    {
        return ['pdo' => ['pdo'], 'begin' => ['begin'], 'immediate' => ['immediate'], 'exclusive' => ['exclusive'], 'savepoint' => ['savepoint'], 'laravel-level' => ['level']];
    }

    #[DataProvider('foreignModes')]
    public function test_t1_07_foreign_transaction_remains_invisible_and_caller_rollbackable(string $mode): void
    {
        $fixture = $this->fixture('T1-07-'.$mode);
        $baseline = $fixture->observe('P');
        $borrowed = $fixture->connect(false);
        self::assertTrue($borrowed->beginTransaction());
        $borrowed->exec('UPDATE coordination SET admission_version=13');
        $this->failure(fn () => PrivateConnectionFactory::rejectBorrowed($borrowed), 'ForeignHandleRejected');
        self::assertTrue($borrowed->inTransaction());
        self::assertSame(13, (int) $borrowed->query('SELECT admission_version FROM coordination')->fetchColumn());
        $this->assertSnapshot($fixture->observe('DURING'), 0, $baseline);
        self::assertTrue($borrowed->rollBack());
        $borrowed = null;
        $faults = new FaultPlan(foreignMode: $mode);
        [$result, $factory] = $this->execute($fixture, $faults);
        self::assertSame('REJECTED_FOREIGN', $result->localStatus);
        self::assertSame(0, $result->callbackCount);
        self::assertSame(7, (int) $faults->callerPdo()->query('SELECT admission_version FROM coordination')->fetchColumn());
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertSame([], $this->events($result, 'COMMIT_REQUESTED'));
        $during = $fixture->observe('DURING');
        if ($mode === 'exclusive') {
            self::assertSame('BLOCKED', $during['status']);
            self::assertArrayNotHasKey('tables', $during);
        } else {
            $this->assertSnapshot($during, 0, $baseline);
        }
        $faults->callerRollback();
        $factory->releaseAfterCaller();
        $this->assertSnapshot($fixture->observe('S'), 0, $baseline);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function coordinationFailures(): array
    {
        return ['invocation-epoch' => ['spec'], 'missing-row' => ['missing'], 'database-epoch' => ['epoch'], 'cas-version' => ['version'], 'setup-error' => ['setup']];
    }

    #[DataProvider('coordinationFailures')]
    public function test_t1_08_coordination_mismatch_aborts_before_callback(string $variant): void
    {
        $fixture = $this->fixture('T1-08-'.$variant);
        $faults = new FaultPlan($variant === 'setup' ? [FaultPoint::BEFORE_COORDINATION->value => 'throw'] : [], coordinationFault: in_array($variant, ['missing', 'epoch', 'version'], true) ? $variant : null);
        $factory = new PrivateConnectionFactory($fixture, $faults);
        $invocation = new InvocationSpec('coord-mismatch', $variant === 'spec' ? str_repeat('f', 32) : $fixture->epoch());
        $result = $factory->coordinator()->run($invocation, OwnershipCoordinator::program(...));
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame($variant === 'setup' ? 'InjectedFailure' : 'CoordinationMismatch', $result->primaryError->reason);
        self::assertSame(0, $result->callbackCount);
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public function test_t1_09_structural_code_one_and_exact_diagnostic_classification(): void
    {
        $fixture = $this->fixture('T1-09');
        $caught = null;
        [$result, $factory] = $this->execute($fixture, new FaultPlan(allowBrokenStatement: true), function (SessionLease $lease) use (&$caught): void {
            try {
                $lease->write(ProbeOperation::BROKEN_STATEMENT);
            } catch (LabFailure $error) {
                $caught = $error;
            }
        });
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame($caught->pdoCause(), $result->primaryError->pdoCause());
        self::assertSame(1, LabFailure::sqliteCode($result->primaryError));
        self::assertFalse(LabFailure::isForeignBegin($result->primaryError));
        self::assertStringNotContainsString('missing_lab_table', json_encode($result));
        $canonical = 'cannot start a transaction within a transaction';
        self::assertTrue(LabFailure::isForeignBegin(FaultPlan::diagnostic('HY000', 1, $canonical)));
        foreach ([['HY000', 1, 'no such table: missing'], ['HY000', '1', $canonical], ['42000', 1, $canonical], ['HY000', 1, $canonical.'.'], ['HY000', 257, $canonical], ['HY000', 6, 'database table is locked'], ['HY000', 8, 'readonly database'], ['HY000', 261, 'database is locked']] as [$state, $code, $message]) {
            $error = FaultPlan::diagnostic($state, $code, $message);
            self::assertFalse(LabFailure::isForeignBegin($error));
            self::assertNotSame(5, LabFailure::sqliteCode($error));
        }
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function identityFailures(): array
    {
        return ['identity' => ['identity'], 'pid' => ['pid'], 'generation' => ['generation']];
    }

    #[DataProvider('identityFailures')]
    public function test_t1_10_identity_loss_cannot_move_witness_or_close(string $kind): void
    {
        $fixture = $this->fixture('T1-10-'.$kind);
        [$result, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::BEFORE_DATA->value => $kind]));
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame([], $this->events($result, 'WRITE_A'));
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertCount(1, $this->events($result, 'INJECTED_IDENTITY_EXPECTATION'));
        $native = $this->events($result, 'NATIVE_STARTED')[0];
        self::assertSame($native['pdo_id'], spl_object_id($factory->weakPdo()->get()));
        if ($kind === 'generation') {
            self::assertSame($native['generation'] + 1, $this->events($result, 'INJECTED_IDENTITY_EXPECTATION')[0]['generation']);
        }
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public function test_t1_11_callback_failure_keeps_original_cause_and_rolls_back_everything(): void
    {
        $fixture = $this->fixture('T1-11');
        $cause = new RuntimeException('Synthetic callback failure');
        [$result, $factory] = $this->execute($fixture, program: function (SessionLease $lease) use ($cause): void {
            FakePortA::write($lease);
            throw $cause;
        });
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame($cause, $result->primaryError->getPrevious());
        self::assertSame(1, $result->callbackCount);
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public function test_t1_12_caught_engine_abort_poison_prevents_autocommit_writes(): void
    {
        $fixture = $this->fixture('T1-12', 'engine_abort');
        $caught = [];
        [$result, $factory] = $this->execute($fixture, program: function (SessionLease $lease) use (&$caught): void {
            FakePortA::write($lease);
            try {
                FakePortB::write($lease, -777);
            } catch (LabFailure $error) {
                $caught[] = $error;
            }
            try {
                ProbeOutbox::write($lease);
            } catch (LabFailure $error) {
                $caught[] = $error;
            }
        });
        self::assertCount(2, $caught);
        self::assertSame('SessionPoisoned', $caught[1]->reason);
        self::assertSame($caught[0]->pdoCause(), $result->primaryError->pdoCause());
        self::assertSame([], $this->events($result, 'WRITE_OUTBOX'));
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        $snapshot = $fixture->observe('U', false);
        self::assertSame('UNKNOWN', $snapshot['status']);
        self::assertSame([], $snapshot['tables']['probe_a']);
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public function test_t1_13_failure_after_port_a_leaves_no_partial_effect(): void
    {
        $fixture = $this->fixture('T1-13');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::AFTER_A->value => 'throw']));
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(1, $this->events($result, 'WRITE_A'));
        self::assertSame([], $this->events($result, 'WRITE_B'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function laterWrites(): array
    {
        return ['port-b' => [FaultPoint::AFTER_B], 'outbox' => [FaultPoint::AFTER_OUTBOX]];
    }

    #[DataProvider('laterWrites')]
    public function test_t1_14_failure_after_b_or_outbox_rolls_back_graph(FaultPoint $point): void
    {
        $fixture = $this->fixture('T1-14');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([$point->value => 'throw']));
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(1, $this->events($result, 'WRITE_B'));
        self::assertCount($point === FaultPoint::AFTER_OUTBOX ? 1 : 0, $this->events($result, 'WRITE_OUTBOX'));
        self::assertSame([], $this->events($result, 'COMMIT_REQUESTED'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public function test_t1_15_receipt_and_outbox_also_roll_back_after_seal(): void
    {
        $fixture = $this->fixture('T1-15');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::AFTER_RECEIPT->value => 'throw']));
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertCount(1, $this->events($result, 'WRITE_RECEIPT'));
        self::assertSame([], $this->events($result, 'COMMIT_REQUESTED'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public function test_t1_16_database_constraints_reject_partial_cross_links_and_mutation(): void
    {
        $fixture = $this->fixture('T1-16');
        [$result, $factory] = $this->execute($fixture);
        self::assertSame(NativeOutcome::COMMITTED, $result->nativeOutcome);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
        $baseline = $fixture->observe('S');
        $pdo = $fixture->connect(false);
        foreach (['missing_receipt', 'cross_identity', 'missing_b', 'update', 'delete', 'replace', 'duplicate'] as $variant) {
            self::assertTrue($pdo->beginTransaction());
            $caught = null;
            $committed = false;
            try {
                match ($variant) {
                    'missing_receipt' => $pdo->exec("INSERT INTO probe_a VALUES ('negative',11,'i-negative')"),
                    'cross_identity' => $pdo->exec("INSERT INTO probe_a VALUES ('negative',11,'i-first'); INSERT INTO probe_b VALUES ('negative',22,'i-other'); INSERT INTO probe_outbox VALUES ('negative','i-other','e-negative','probe',0); INSERT INTO probe_receipt VALUES ('negative','i-other',11,22,'e-negative',1)"),
                    'missing_b' => $pdo->exec("INSERT INTO probe_a VALUES ('negative',11,'i-negative'); INSERT INTO probe_outbox VALUES ('negative','i-negative','e-negative','probe',0); INSERT INTO probe_receipt VALUES ('negative','i-negative',11,22,'e-negative',1)"),
                    'update' => $pdo->exec('UPDATE probe_a SET a_value=a_value'),
                    'delete' => $pdo->exec('DELETE FROM probe_a; DELETE FROM probe_b; DELETE FROM probe_outbox; DELETE FROM probe_receipt'),
                    'replace' => $pdo->exec('INSERT OR REPLACE INTO probe_a SELECT * FROM probe_a'),
                    'duplicate' => $pdo->exec("INSERT INTO probe_a VALUES ('synthetic-probe',11,'i-new')"),
                };
                $committed = $pdo->commit();
            } catch (PDOException $error) {
                $caught = $error;
            } finally {
                if (! $committed) {
                    self::assertTrue($pdo->rollBack()); // Harness owns this exact successful BEGIN.
                }
            }
            self::assertInstanceOf(PDOException::class, $caught, $variant);
            self::assertSame(19, LabFailure::sqliteCode($caught));
            if (in_array($variant, ['update', 'delete', 'replace', 'duplicate'], true)) {
                self::assertSame(in_array($variant, ['update', 'delete'], true) ? 'immutable_probe' : 'duplicate_probe', $caught->errorInfo[2]);
            }
            $this->assertSnapshot($fixture->observe('S'), 1, $baseline);
        }
        $pdo = null;
    }

    public static function terminalStates(): array
    {
        return ['known-close' => [false], 'quarantine' => [true]];
    }

    #[DataProvider('terminalStates')]
    public function test_t1_21_terminal_lease_and_connection_cannot_be_reused(bool $uncertain): void
    {
        $fixture = $this->fixture('T1-21');
        $lateLease = null;
        $faults = new FaultPlan($uncertain ? [FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER->value => 'throw'] : []);
        [$result, $factory] = $this->execute($fixture, $faults, function (SessionLease $lease) use (&$lateLease): void {
            $lateLease = $lease;
            OwnershipCoordinator::program($lease);
        });
        $this->failure(fn () => FakePortA::write($lateLease), 'SessionRevoked');
        if ($uncertain) {
            $pdo = $factory->weakPdo();
            $connection = $factory->weakConnection();
            unset($factory);
            $this->assertRootCustody($pdo, $connection, $fixture);
        } else {
            self::assertSame(NativeOutcome::COMMITTED, $result->nativeOutcome);
            $this->failure(fn () => $factory->coordinator(), 'ConnectionInvalidated');
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
            $this->assertCommittedSnapshot($fixture->observe('S'), 1);
        }
    }

    public static function forbiddenFacilities(): array
    {
        $values = ['begin', 'transaction', 'after_commit', 'before_begin', 'before_query', 'manager', 'reconnector', 'read_pdo', 'raw', 'postseal'];

        return array_combine($values, array_map(fn ($v) => [$v], $values));
    }

    #[DataProvider('forbiddenFacilities')]
    public function test_t1_22_facilities_and_postseal_errors_poison_even_when_caught(string $facility): void
    {
        $fixture = $this->fixture('T1-22-'.$facility);
        $factory = new PrivateConnectionFactory($fixture);
        $hidden = 0;
        $caught = [];
        $result = $factory->coordinator()->run(new InvocationSpec('forbidden-facility', $fixture->epoch()), function (SessionLease $lease) use ($factory, $facility, &$hidden, &$caught): void {
            try {
                $connection = $factory->weakConnection()->get();
                $hook = function () use (&$hidden): void {
                    $hidden++;
                };
                if ($facility === 'postseal') {
                    OwnershipCoordinator::program($lease);
                    FakePortA::write($lease);
                } else {
                    match ($facility) {
                        'begin' => $connection->beginTransaction(),
                        'transaction' => $connection->transaction($hook, 3),
                        'after_commit' => $connection->afterCommit($hook),
                        'before_begin' => $connection->beforeStartingTransaction($hook),
                        'before_query' => $connection->beforeExecuting($hook),
                        'manager' => $connection->setTransactionManager(new \stdClass),
                        'reconnector' => $connection->setReconnector($hook),
                        'read_pdo' => $connection->setReadPdo(null),
                        'raw' => $connection->getRawPdo(),
                    };
                }
            } catch (LabFailure $error) {
                $caught[] = $error;
            }
            try {
                ProbeOutbox::write($lease);
            } catch (LabFailure $error) {
                $caught[] = $error;
            }
        });
        self::assertSame(0, $hidden);
        self::assertCount(2, $caught);
        self::assertSame('SessionPoisoned', $caught[1]->reason);
        self::assertSame(NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
        self::assertSame($caught[0], $result->primaryError);
        self::assertSame([], $this->events($result, 'COMMIT_REQUESTED'));
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function rollbackFailures(): array
    {
        return ['false' => [FaultPoint::BEFORE_NATIVE_ROLLBACK, 'false'], 'exception' => [FaultPoint::BEFORE_NATIVE_ROLLBACK, 'throw'], 'lost-marker' => [FaultPoint::AFTER_NATIVE_ROLLBACK_BEFORE_MARKER, 'throw']];
    }

    #[DataProvider('rollbackFailures')]
    public function test_t1_23_failed_rollback_preserves_cause_and_root_custody(FaultPoint $point, string $kind): void
    {
        $fixture = $this->fixture('T1-23');
        $primary = new RuntimeException('Synthetic primary failure');
        [$result, $factory] = $this->execute($fixture, new FaultPlan([$point->value => $kind]), function (SessionLease $lease) use ($primary): void {
            FakePortA::write($lease);
            throw $primary;
        });
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame($primary, $result->primaryError->getPrevious());
        self::assertCount(1, $result->secondaryErrors);
        self::assertCount(1, $this->events($result, 'ROLLBACK_REQUESTED'));
        self::assertSame([], $this->events($result, 'ROLLBACK_CONFIRMED'));
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public static function confirmedCloseFailures(): array
    {
        return ['commit-bookkeeping' => ['commit'], 'rollback-bookkeeping' => ['rollback'], 'late-acknowledgement' => ['late'], 'evidence-failure' => ['evidence']];
    }

    #[DataProvider('confirmedCloseFailures')]
    public function test_t1_24_native_marker_survives_bookkeeping_deadline_and_evidence_failure(string $variant): void
    {
        $fixture = $this->fixture('T1-24-'.$variant);
        $faults = match ($variant) {
            'commit' => new FaultPlan([FaultPoint::AFTER_COMMIT_MARKER->value => 'throw']),
            'rollback' => new FaultPlan([FaultPoint::AFTER_ROLLBACK_MARKER->value => 'throw']),
            'late' => new FaultPlan([FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER->value => 'delay']),
            default => new FaultPlan,
        };
        if ($variant === 'evidence') {
            mkdir($fixture->directory.'/runs.jsonl', 0700);
        }
        $factory = new PrivateConnectionFactory($fixture, $faults);
        $caught = null;
        try {
            $result = $factory->coordinator()->run(new InvocationSpec('confirmed-boundary', $fixture->epoch()), $variant === 'rollback' ? static function (): void {
                throw new RuntimeException('Synthetic callback failure');
            } : OwnershipCoordinator::program(...));
        } catch (LabFailure $error) {
            $caught = $error;
            $result = $error->result;
        }
        self::assertSame($variant === 'rollback' ? NativeOutcome::ROLLED_BACK : NativeOutcome::COMMITTED, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        self::assertCount(in_array($variant, ['commit', 'rollback'], true) ? 1 : 0, $result->secondaryErrors);
        if ($variant === 'late') {
            $elapsed = $this->events($result, 'COMMIT_CONFIRMED')[0]['time_ns'] - $this->events($result, 'COMMIT_REQUESTED')[0]['time_ns'];
            self::assertGreaterThan(250_000_000, $elapsed);
        }
        if ($variant === 'evidence') {
            self::assertSame('EvidenceFailure', $caught->reason);
            self::assertTrue(PrivateConnectionFactory::admissionClosed());
            $fixture->record('release', ['expected_evidence_failure' => $caught->jsonSerialize(), 'preserved_result' => $result]);
        }
        if ($variant === 'rollback') {
            $this->assertSnapshot($fixture->observe('S'), 0);
        } else {
            $this->assertCommittedSnapshot($fixture->observe('S'), 1);
        }
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }
}
