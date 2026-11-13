<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\BookingOwnershipLab\FakePortA;
use Tests\Support\BookingOwnershipLab\FakePortB;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\LabFixture;
use Tests\Support\BookingOwnershipLab\NativeOutcome;
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
        $this->assertSnapshot($post, 1);
        self::assertSame($spec->id, $post['tables']['probe_receipt'][0]['invocation_id']);
        self::assertSame(1, (int) $post['tables']['coordination'][0]['admission_version']);
        self::assertSame('RELEASED', $factory->auditRelease()['status']);
    }
}
