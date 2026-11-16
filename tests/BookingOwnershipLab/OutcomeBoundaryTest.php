<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\FaultPoint;
use Tests\Support\BookingOwnershipLab\LabFailure;
use Tests\Support\BookingOwnershipLab\NativeOutcome;
use Tests\Support\BookingOwnershipLab\PrivateConnectionFactory;
use Tests\Support\BookingOwnershipLab\ProbeReceipt;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OutcomeBoundaryTest extends LabTestCase
{
    public function test_t1_25_receipt_observation_does_not_replay_or_rewrite_unknown(): void
    {
        $fixture = $this->fixture('T1-25');
        [$result, $factory, $spec] = $this->execute($fixture, new FaultPlan([FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER->value => 'throw']));
        $original = json_encode($result);
        $observation = ProbeReceipt::observe($fixture, $spec);
        self::assertSame('RECEIPT_OBSERVED', $observation->kind);
        self::assertTrue($observation->graphValid);
        self::assertFalse($observation->jsonSerialize()['retry_permitted']);
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame($original, json_encode($result));
        self::assertSame(1, $result->callbackCount);
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public static function absentObservations(): array
    {
        return ['absent' => [null], 'busy-injected' => ['busy'], 'open-injected' => ['open']];
    }

    #[DataProvider('absentObservations')]
    public function test_t1_26_no_receipt_or_unavailable_reader_never_certifies_absence(?string $fault): void
    {
        $fixture = $this->fixture('T1-26');
        [$result, $factory, $spec] = $this->execute($fixture, new FaultPlan([FaultPoint::BEFORE_NATIVE_COMMIT->value => 'false']));
        if ($fault === null) {
            $observation = ProbeReceipt::observe($fixture, $spec);
            self::assertSame('NO_RECEIPT_OBSERVED', $observation->kind);
            self::assertFalse($observation->jsonSerialize()['retry_permitted']);
            self::assertArrayNotHasKey('not_committed', $observation->jsonSerialize());
        } else {
            $error = $this->failure(fn () => ProbeReceipt::observe($fixture, $spec, fault: $fault), 'ObservationUnavailable');
            if ($fault === 'busy') {
                self::assertSame(5, LabFailure::sqliteCode($error));
            }
        }
        self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
        self::assertSame(1, $result->callbackCount);
        self::assertSame([], $this->events($result, 'ROLLBACK_REQUESTED'));
        $pdo = $factory->weakPdo();
        $connection = $factory->weakConnection();
        unset($factory);
        $this->assertRootCustody($pdo, $connection, $fixture);
    }

    public static function cleanupHomologies(): array
    {
        return ['R02-preflight' => ['preflight'], 'R08-read' => ['read'], 'R11-write-error' => ['write'], 'R15-close-error' => ['close'], 'R05-unknown-start' => ['unknown']];
    }

    #[DataProvider('cleanupHomologies')]
    public function test_t1_27_cleanup_guards_are_homologues_without_implementing_resolver(string $variant): void
    {
        $fixture = $this->fixture('T1-27-'.$variant);
        $faults = match ($variant) {
            'preflight' => new FaultPlan(profileProbe: 'silent'),
            'read' => new FaultPlan(allowBrokenStatement: true),
            'write' => new FaultPlan([FaultPoint::AFTER_OUTBOX->value => 'throw']),
            'close' => new FaultPlan([FaultPoint::AFTER_A->value => 'throw', FaultPoint::BEFORE_NATIVE_ROLLBACK->value => 'false']),
            default => new FaultPlan([FaultPoint::AFTER_NATIVE_BEFORE_WITNESS->value => 'throw']),
        };
        [$result, $factory] = $this->execute($fixture, $faults, $variant === 'read' ? static fn ($lease) => $lease->write(\Tests\Support\BookingOwnershipLab\ProbeOperation::BROKEN_STATEMENT) : null);
        if (in_array($variant, ['close', 'unknown'], true)) {
            self::assertSame(NativeOutcome::UNKNOWN, $result->nativeOutcome);
            $pdo = $factory->weakPdo();
            $connection = $factory->weakConnection();
            unset($factory);
            $this->assertRootCustody($pdo, $connection, $fixture);
        } else {
            self::assertSame($variant === 'preflight' ? NativeOutcome::NOT_STARTED : NativeOutcome::ROLLED_BACK, $result->nativeOutcome);
            self::assertCount($variant === 'preflight' ? 0 : 1, $this->events($result, 'ROLLBACK_CONFIRMED'));
            $this->assertSnapshot($fixture->observe('S'), 0);
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
        }
        self::assertSame([], $this->events($result, 'COMMIT_REQUESTED'));
        $reader = $fixture->connect(true);
        $tables = $reader->query("SELECT name FROM sqlite_schema WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $reader = null;
        self::assertSame(['coordination', 'probe_a', 'probe_b', 'probe_outbox', 'probe_receipt'], $tables);
    }

    public static function explicitNewInvocations(): array
    {
        return ['not-started' => ['not-started'], 'rolled-back' => ['rolled-back'], 'unknown-prohibited' => ['unknown']];
    }

    #[DataProvider('explicitNewInvocations')]
    public function test_t1_28_only_known_abort_allows_explicit_fresh_invocation(string $variant): void
    {
        $fixture = $this->fixture('T1-28-'.$variant);
        $point = match ($variant) {
            'not-started' => FaultPoint::BEFORE_NATIVE_BEGIN,
            'rolled-back' => FaultPoint::AFTER_A,
            default => FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER,
        };
        [$first, $factory, $firstSpec] = $this->execute($fixture, new FaultPlan([$point->value => 'throw']));
        if ($variant === 'unknown') {
            self::assertSame(NativeOutcome::UNKNOWN, $first->nativeOutcome);
            $this->failure(fn () => new PrivateConnectionFactory($fixture), 'ConnectionInvalidated');
            self::assertSame(1, $first->callbackCount);
            self::assertSame('UNKNOWN', $fixture->observe('U', false)['status']);
        } else {
            self::assertSame($variant === 'not-started' ? NativeOutcome::NOT_STARTED : NativeOutcome::ROLLED_BACK, $first->nativeOutcome);
            $this->assertSnapshot($fixture->observe('S'), 0);
            self::assertSame('RELEASED', $factory->auditRelease()['status']);
            [$second, $newFactory, $newSpec] = $this->execute($fixture);
            self::assertSame(NativeOutcome::COMMITTED, $second->nativeOutcome);
            self::assertSame(1, $second->callbackCount);
            self::assertNotSame($firstSpec->id, $newSpec->id);
            self::assertNotSame($first->trace[0]['connection_epoch'], $second->trace[0]['connection_epoch']);
            $snapshot = $fixture->observe('S');
            $this->assertCommittedSnapshot($snapshot, 1);
            self::assertSame($newSpec->id, $snapshot['tables']['probe_receipt'][0]['invocation_id']);
            self::assertSame('RELEASED', $newFactory->auditRelease()['status']);
        }
    }

    public function test_t1_29_storage_uniqueness_is_not_exactly_once_business_execution(): void
    {
        $fixture = $this->fixture('T1-29');
        [$first, $factory, $firstSpec] = $this->execute($fixture);
        self::assertSame(NativeOutcome::COMMITTED, $first->nativeOutcome);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
        $baseline = $fixture->observe('S');
        [$second, $newFactory, $newSpec] = $this->execute($fixture);
        self::assertSame(NativeOutcome::ROLLED_BACK, $second->nativeOutcome);
        self::assertSame(19, LabFailure::sqliteCode($second->primaryError));
        self::assertNotSame($firstSpec->id, $newSpec->id);
        self::assertSame(1, $first->callbackCount);
        self::assertSame(1, $second->callbackCount);
        $this->assertSnapshot($fixture->observe('S'), 1, $baseline);
        self::assertSame('RECEIPT_OBSERVED', ProbeReceipt::observe($fixture, $firstSpec, true)->kind);
        self::assertSame('NO_RECEIPT_OBSERVED', ProbeReceipt::observe($fixture, $newSpec, true)->kind);
        self::assertSame('RELEASED', $newFactory->auditRelease()['status']);
    }

    public function test_t1_32_source_dependencies_isolation_and_case_manifest_are_complete(): void
    {
        self::assertSame('1', ini_get('zend.exception_ignore_args'));
        $source = dirname(__DIR__, 2);
        $base = json_decode(file_get_contents('/evidence/before.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ac999cea8f592ce7b94481ee30f602b67d9f5bea', $base['base']);
        self::assertCount(1444, $base['tracked']);
        foreach ($base['tracked'] as $relative => $hash) {
            self::assertSame($hash, hash_file('sha256', $source.'/'.$relative), $relative);
        }
        $manifest = json_decode(file_get_contents(getenv('T1_SOURCE_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1464, $manifest);
        foreach ($manifest as $relative => $hash) {
            self::assertFalse(str_starts_with($relative, '/') || str_contains($relative, '..'));
            self::assertSame($hash, hash_file('sha256', $source.'/'.$relative), $relative);
        }
        $lock = json_decode(file_get_contents($source.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            self::assertSame($package['source']['reference'] ?? $package['dist']['reference'], InstalledVersions::getReference($package['name']), $package['name']);
        }
        $cases = [];
        foreach (['FixtureProfileTest', 'OwnershipLifecycleTest', 'BusyConcurrencyTest', 'OutcomeBoundaryTest'] as $test) {
            require_once __DIR__.'/'.$test.'.php';
            $class = new ReflectionClass(__NAMESPACE__.'\\'.$test);
            self::assertCount(1, $class->getAttributes(RunTestsInSeparateProcesses::class));
            foreach ($class->getMethods() as $method) {
                if (preg_match('/\Atest_t1_(\d{2})_/', $method->name, $match)) {
                    self::assertArrayNotHasKey($match[1], $cases);
                    $cases[$match[1]] = $class->name.'::'.$method->name;
                }
            }
        }
        ksort($cases);
        self::assertSame(array_map(fn ($i) => sprintf('%02d', $i), range(1, 32)), array_map('strval', array_keys($cases)));
        file_put_contents(\Tests\Support\BookingOwnershipLab\LabFixture::runRoot().'/case-map.json', json_encode($cases, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::assertFalse(class_exists(\Tests\TestCase::class, false));
        self::assertFalse(is_file($source.'/.env'));
        foreach (get_included_files() as $file) {
            self::assertFalse(str_starts_with($file, $source.'/app/') || in_array($file, [$source.'/bootstrap/app.php', $source.'/tests/bootstrap.php', $source.'/tests/TestCase.php'], true), $file);
        }
        $config = file_get_contents(__DIR__.'/phpunit.xml');
        self::assertStringContainsString('bootstrap="bootstrap.php"', $config);
        self::assertStringNotContainsString('Unit', $config);
        self::assertStringNotContainsString('Feature', $config);
    }
}
