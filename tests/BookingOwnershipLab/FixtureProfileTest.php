<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use PDOException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\BookingOwnershipLab\FakePortA;
use Tests\Support\BookingOwnershipLab\FakePortB;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\FaultPoint;
use Tests\Support\BookingOwnershipLab\LabFailure;
use Tests\Support\BookingOwnershipLab\LabFixture;
use Tests\Support\BookingOwnershipLab\NativeOutcome;
use Tests\Support\BookingOwnershipLab\PrivateConnectionFactory;
use Tests\Support\BookingOwnershipLab\ProbeOutbox;
use Tests\Support\BookingOwnershipLab\ProbeReceipt;
use Tests\Support\BookingOwnershipLab\SessionLease;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FixtureProfileTest extends LabTestCase
{
    public static function oracleStates(): array
    {
        return ['normal' => [null], 'unknown-reader-close' => ['close_false'], 'unknown-reader-begin' => ['begin_uncertain']];
    }

    #[DataProvider('oracleStates')]
    public function test_t1_01_fixture_paths_profile_and_oracle_failures(?string $oracleFault): void
    {
        $fixture = $this->fixture('T1-01');
        if ($oracleFault !== null) {
            $unknown = $fixture->observe('S', true, $oracleFault);
            self::assertSame('UNKNOWN', $unknown['status']);
            self::assertFalse($unknown['read_complete']);
            self::assertSame('retained_reader_quarantine', $unknown['cleanup_state']);
            self::assertSame($oracleFault === 'close_false' ? 1 : 0, $unknown['reader_close_calls']);
            $weak = LabFixture::weakRetainedReader($unknown['snapshot_id']);
            gc_collect_cycles();
            self::assertNotNull($weak->get());
            self::assertTrue(\Tests\Support\BookingOwnershipLab\PrivateConnectionFactory::admissionClosed());
            $this->failure(fn () => new \Tests\Support\BookingOwnershipLab\PrivateConnectionFactory($fixture), 'ConnectionInvalidated');

            return;
        }
        $this->failure(fn () => $fixture->initialize(), 'ExistingFixtureRejected');
        $this->failure(fn () => LabFixture::load($fixture->directory, str_repeat('0', 64)), 'FixtureMismatch');
        $this->failure(fn () => LabFixture::load($fixture->directory.'/../foreign', $fixture->hash()), 'PathRejected');
        $path = $fixture->path();
        rename($path, $path.'.own-backup');
        symlink($path.'.own-backup', $path);
        $this->failure(fn () => $fixture->connect(false), 'PathRejected');
        unlink($path);
        rename($path.'.own-backup', $path);
        $this->assertSnapshot($fixture->observe('S'), 0);
        foreach (['open', 'busy'] as $fault) {
            $blocked = $fixture->observe('S', true, $fault);
            self::assertSame('BLOCKED', $blocked['status']);
            self::assertFalse($blocked['read_complete']);
            self::assertArrayNotHasKey('tables', $blocked);
            self::assertArrayNotHasKey('foreign_key_check', $blocked);
        }
        self::assertSame(0700, fileperms($fixture->directory) & 0777);
        self::assertSame(0600, fileperms($fixture->path()) & 0777);
    }

    public static function rejectedProfiles(): array
    {
        return array_combine($values = ['silent', 'warning', 'persistent', 'lazy', 'replica', 'raw', 'read_pdo', 'manager', 'event', 'reconnector', 'nested', 'after_commit', 'before_execute'], array_map(fn ($v) => [$v], $values));
    }

    #[DataProvider('rejectedProfiles')]
    public function test_t1_02_unsupported_profile_before_begin(string $probe): void
    {
        $fixture = $this->fixture('T1-02-'.$probe);
        [$result, $factory] = $this->execute($fixture, new FaultPlan(profileProbe: $probe));
        self::assertSame('BLOCKED_PROFILE', $result->localStatus);
        self::assertSame(NativeOutcome::NOT_STARTED, $result->nativeOutcome);
        self::assertSame(0, $result->callbackCount);
        self::assertSame([], $this->events($result, 'NATIVE_STARTED'));
        self::assertContains($result->primaryError->reason, ['ProfileRejected', 'RawHandleRejected']);
        $this->assertSnapshot($fixture->observe('S'), 0);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public function test_t1_03_native_return_before_level_and_independent_visibility(): void
    {
        $fixture = $this->fixture('T1-03');
        $during = null;
        $ownerId = null;
        $factory = new \Tests\Support\BookingOwnershipLab\PrivateConnectionFactory($fixture);
        $spec = new \Tests\Support\BookingOwnershipLab\InvocationSpec('successful-probe', $fixture->epoch());
        $result = $factory->coordinator()->run($spec, function (SessionLease $lease) use ($fixture, $factory, &$during, &$ownerId): void {
            FakePortA::write($lease);
            $ownerId = spl_object_id($factory->weakPdo()->get());
            $during = $fixture->observe('DURING');
            FakePortB::write($lease);
            ProbeOutbox::write($lease);
            ProbeReceipt::seal($lease);
        });
        self::assertSame(NativeOutcome::COMMITTED, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        $native = $this->events($result, 'NATIVE_STARTED')[0];
        $active = $this->events($result, 'OWN_ACTIVE')[0];
        self::assertSame(0, $native['laravel_level']);
        self::assertSame(1, $active['laravel_level']);
        self::assertLessThan($active['seq'], $native['seq']);
        self::assertNotSame($ownerId, $during['pdo_id']);
        $this->assertSnapshot($during, 0);
        $post = $fixture->observe('S');
        $this->assertCommittedSnapshot($post, 1);
        self::assertSame($spec->id, $post['tables']['probe_receipt'][0]['invocation_id']);
        self::assertSame(1, (int) $post['tables']['coordination'][0]['admission_version']);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }

    public static function fix1ObservationFailures(): array
    {
        return [
            'fk-and-close' => [true, 'close_false', 'FAIL', 'FAIL', true, false],
            'fk-only' => [true, null, 'FAIL', 'FAIL', true, true],
            'healthy-close' => [false, 'close_false', 'UNKNOWN', 'PASS', true, false],
            'incomplete-read' => [false, 'read_incomplete', 'BLOCKED', 'UNKNOWN', false, true],
            'incomplete-read-and-close' => [false, 'read_incomplete_close_false', 'UNKNOWN', 'UNKNOWN', false, false],
            'healthy' => [false, null, 'PASS', 'PASS', true, true],
        ];
    }

    #[DataProvider('fix1ObservationFailures')]
    public function test_fix1_observation_preserves_facts_and_separates_cleanup(bool $orphan, ?string $fault, string $status, string $structural, bool $dataComplete, bool $cleanupComplete): void
    {
        $fixture = $this->fixture('T1-01-fix1-observer');
        if ($orphan) {
            // Deliberately invalid synthetic fixture, solely to test the diagnostic oracle.
            $writer = $fixture->connect(false);
            $writer->exec('PRAGMA foreign_keys=OFF');
            $writer->exec("INSERT INTO probe_a VALUES ('fix1-orphan',11,'fix1-invocation')");
            $writer = null;
        }
        $snapshot = $fixture->observe('S', true, $fault);
        self::assertSame($status, $snapshot['status']);
        self::assertSame($structural, $snapshot['structural_status']);
        self::assertSame($dataComplete, $snapshot['data_read_complete']);
        self::assertSame($cleanupComplete, $snapshot['cleanup_complete']);
        self::assertSame($dataComplete && $cleanupComplete, $snapshot['read_complete']);
        self::assertSame([[1]], $snapshot['foreign_keys']);
        self::assertSame([['ok']], $snapshot['integrity_check']);
        if ($orphan) {
            self::assertNotEmpty($snapshot['foreign_key_check']);
            self::assertSame('FAIL', $snapshot['functional_status']);
        } elseif ($dataComplete) {
            self::assertSame([], $snapshot['foreign_key_check']);
            self::assertSame('PASS', $snapshot['functional_status']);
        } else {
            self::assertArrayNotHasKey('foreign_key_check', $snapshot);
            self::assertSame('UNKNOWN', $snapshot['functional_status']);
            self::assertInstanceOf(PDOException::class, $fixture->observationError());
            self::assertSame(10, LabFailure::sqliteCode($fixture->observationError()));
            self::assertSame(10, $snapshot['error']['sqlite_code']);
        }
        self::assertSame(1, $snapshot['reader_close_calls']);
        if (! $cleanupComplete) {
            self::assertSame('UNKNOWN', $snapshot['reader_cleanup_status']);
            self::assertSame('retained_reader_quarantine', $snapshot['cleanup_state']);
            self::assertTrue(PrivateConnectionFactory::admissionClosed());
            $weak = LabFixture::weakRetainedReader($snapshot['snapshot_id']);
            gc_collect_cycles();
            self::assertNotNull($weak->get());
            $this->failure(fn () => new PrivateConnectionFactory($fixture), 'ConnectionInvalidated');
            $error = $fault === 'read_incomplete_close_false' ? $snapshot['secondary_error'] : $snapshot['error'];
            self::assertSame('ObservationCloseUnknown', $error['reason']);
        } else {
            self::assertSame('CLOSED', $snapshot['reader_cleanup_status']);
            self::assertNull(LabFixture::weakRetainedReader($snapshot['snapshot_id']));
            self::assertFalse(PrivateConnectionFactory::admissionClosed());
        }
        $lines = file($fixture->directory.'/observations.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertSame($snapshot, json_decode($lines[array_key_last($lines)], true, flags: JSON_THROW_ON_ERROR));
    }

    public static function fix1SnapshotChanges(): array
    {
        return [
            'coordination-version' => ['coordination', 'admission_version', 9],
            'coordination-nonce' => ['coordination', 'owner_nonce', null],
            'probe-a' => ['probe_a', 'a_value', 12],
            'probe-b' => ['probe_b', 'b_value', 23],
            'outbox' => ['probe_outbox', 'event_key', 'changed-event'],
            'receipt' => ['probe_receipt', 'event_key', 'changed-event'],
        ];
    }

    #[DataProvider('fix1SnapshotChanges')]
    public function test_fix1_abort_snapshot_rejects_changes_in_each_table(string $table, string $column, mixed $value): void
    {
        $fixture = $this->fixture('T1-01-fix1-snapshot');
        $baseline = null;
        if ($table === 'coordination') {
            $nonce = bin2hex(random_bytes(16));
            $writer = $fixture->connect(false);
            $statement = $writer->prepare('UPDATE coordination SET '.$column.'=? WHERE id=1');
            $statement->execute([$column === 'owner_nonce' ? $nonce : $value]);
            $statement = null;
            $writer = null;
            $snapshot = $fixture->observe('S');
            $count = 0;
            if ($column === 'owner_nonce') {
                self::assertSame(hash('sha256', $nonce), $snapshot['tables']['coordination'][0]['owner_nonce']);
                self::assertStringNotContainsString($nonce, json_encode($snapshot));
            }
        } else {
            [$result, $factory] = $this->execute($fixture);
            self::assertSame(NativeOutcome::COMMITTED, $result->nativeOutcome);
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
            $baseline = $fixture->observe('S');
            $snapshot = $fixture->observe('S');
            // Alter only the diagnostic snapshot: keep SQLite's immutable probes intact.
            $snapshot['tables'][$table][0][$column] = $value;
            $count = 1;
        }
        $rejected = false;
        try {
            $this->assertSnapshot($snapshot, $count, $baseline);
        } catch (AssertionFailedError) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'A changed '.$table.' snapshot must be rejected.');
        $fixture->record('release', ['negative_snapshot' => $snapshot, 'baseline' => $baseline, 'expected_rejection' => $rejected, 'changed_table' => $table]);
    }

    public function test_fix1_abort_snapshot_without_prior_baseline_is_rejected(): void
    {
        $fixture = LabFixture::fresh('T1-01-fix1-no-baseline');
        $snapshot = $fixture->observe('S');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An abort snapshot requires a baseline captured before the operation.');
        $this->assertSnapshot($snapshot, 0);
    }

    public static function fix1AbortBaselines(): array
    {
        return ['empty' => [false], 'existing-graph' => [true]];
    }

    #[DataProvider('fix1AbortBaselines')]
    public function test_fix1_abort_preserves_all_tables_and_success_can_change_coordination(bool $existing): void
    {
        $fixture = $this->fixture('T1-01-fix1-baseline');
        if ($existing) {
            [$committed, $firstFactory] = $this->execute($fixture);
            self::assertSame(NativeOutcome::COMMITTED, $committed->nativeOutcome);
            self::assertSame('RELEASED', $firstFactory->auditRelease()['status']);
        }
        $baseline = $fixture->observe($existing ? 'S' : 'P');
        [$aborted, $factory] = $this->execute($fixture, new FaultPlan([FaultPoint::AFTER_A->value => 'throw']), key: 'fix1-abort');
        self::assertSame(NativeOutcome::ROLLED_BACK, $aborted->nativeOutcome);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
        $snapshot = $fixture->observe('S');
        $this->assertSnapshot($snapshot, $existing ? 1 : 0, $existing ? $baseline : null);
        self::assertSame($baseline['tables'], $snapshot['tables']);
        [$committed, $newFactory] = $this->execute($fixture, key: 'fix1-success');
        self::assertSame(NativeOutcome::COMMITTED, $committed->nativeOutcome);
        self::assertSame('RELEASED', $newFactory->auditRelease()['status']);
        $after = $fixture->observe('S');
        $this->assertCommittedSnapshot($after, $existing ? 2 : 1);
        self::assertSame((int) $baseline['tables']['coordination'][0]['admission_version'] + 1, (int) $after['tables']['coordination'][0]['admission_version']);
        self::assertNotSame($baseline['tables']['coordination'][0]['owner_nonce'], $after['tables']['coordination'][0]['owner_nonce']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $after['tables']['coordination'][0]['owner_nonce']);
    }
}
