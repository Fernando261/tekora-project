<?php

declare(strict_types=1);

namespace Tests\BookingOwnershipLab;

use Closure;
use PHPUnit\Framework\TestCase;
use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\InvocationSpec;
use Tests\Support\BookingOwnershipLab\LabFailure;
use Tests\Support\BookingOwnershipLab\LabFixture;
use Tests\Support\BookingOwnershipLab\OwnershipCoordinator;
use Tests\Support\BookingOwnershipLab\PrivateConnectionFactory;
use Tests\Support\BookingOwnershipLab\RunResult;
use WeakReference;

$source = dirname(__DIR__, 2);
// Error traces must not retain PDO, closures or capabilities as argument roots.
// Preserve the original exception instance and diagnostic, but prevent argument capture at creation.
ini_set('zend.exception_ignore_args', '1');
if (ini_get('zend.exception_ignore_args') !== '1') {
    throw new \RuntimeException('Exception argument isolation is required before opening PDO.');
}
if (getenv('T1_ISOLATED') !== '1' || is_file($source.'/.env')) {
    throw new \RuntimeException('Isolated T1 source without application environment required.');
}
foreach (['DB_URL', 'DB_DATABASE', 'APP_KEY', 'STRIPE_SECRET', 'MERCADOPAGO_ACCESS_TOKEN', 'HTTP_PROXY', 'HTTPS_PROXY'] as $name) {
    if (getenv($name) !== false && getenv($name) !== '') {
        throw new \RuntimeException('Unexpected environment in isolated laboratory.');
    }
}
require_once $source.'/vendor/autoload.php';
if (\Composer\InstalledVersions::getPrettyVersion('laravel/framework') !== 'v12.71.0' || \PHPUnit\Runner\Version::id() !== '11.5.57') {
    throw new \RuntimeException('Pinned laboratory dependencies required.');
}
foreach (['LabTypes', 'LabFailure', 'TransactionWitness', 'FaultPlan', 'LabFixture', 'PrivateConnectionFactory', 'ObservedSqliteConnection', 'SessionLease', 'OwnershipCoordinator', 'FakePortA', 'FakePortB', 'ProbeOutbox', 'ProbeReceipt'] as $module) {
    require_once $source.'/tests/Support/BookingOwnershipLab/'.$module.'.php';
}
LabFixture::runRoot();
FaultPlan::assertNoInheritedHandles();

/** Pure PHPUnit harness; never boots the Laravel application. */
abstract class LabTestCase extends TestCase
{
    protected function fixture(string $case, string $variant = 'normal'): LabFixture
    {
        $fixture = LabFixture::fresh($case, $variant);
        $this->assertSnapshot($fixture->observe('P'), 0);

        return $fixture;
    }

    protected function execute(LabFixture $fixture, ?FaultPlan $faults = null, ?Closure $program = null, string $key = 'synthetic-probe'): array
    {
        $factory = new PrivateConnectionFactory($fixture, $faults);
        $invocation = new InvocationSpec($key, $fixture->epoch());
        $result = $factory->coordinator()->run($invocation, $program ?? OwnershipCoordinator::program(...));
        $this->assertNoTraceArguments($result->primaryError);
        foreach ($result->secondaryErrors as $error) {
            $this->assertNoTraceArguments($error);
        }

        return [$result, $factory, $invocation];
    }

    protected function assertNoTraceArguments(?\Throwable $error): void
    {
        while ($error !== null) {
            foreach ($error->getTrace() as $frame) {
                self::assertSame([], $frame['args'] ?? [], 'Exception arguments must not retain handles or closures.');
            }
            $error = $error->getPrevious();
        }
    }

    protected function assertSnapshot(array $snapshot, int $count, ?array $baseline = null): void
    {
        self::assertSame('PASS', $snapshot['status'], json_encode($snapshot));
        self::assertTrue($snapshot['read_complete']);
        self::assertSame([['ok']], $snapshot['integrity_check']);
        self::assertSame([], $snapshot['foreign_key_check']);
        self::assertCount(1, $snapshot['foreign_keys']);
        self::assertSame(1, (int) $snapshot['foreign_keys'][0][0]);
        foreach (['probe_a', 'probe_b', 'probe_outbox', 'probe_receipt'] as $table) {
            self::assertCount($count, $snapshot['tables'][$table], $table);
        }
        foreach ($snapshot['tables']['probe_receipt'] as $receipt) {
            self::assertSame(11, (int) $receipt['a_value']);
            self::assertSame(22, (int) $receipt['b_value']);
            foreach (['probe_a', 'probe_b', 'probe_outbox'] as $table) {
                $matching = array_values(array_filter($snapshot['tables'][$table], fn ($row) => $row['probe_key'] === $receipt['probe_key']));
                self::assertCount(1, $matching);
                self::assertSame($receipt['invocation_id'], $matching[0]['invocation_id']);
            }
        }
        if ($baseline !== null) {
            self::assertSame($baseline['tables'], $snapshot['tables']);
        }
    }

    protected function failure(Closure $action, string $reason): LabFailure
    {
        try {
            $action();
        } catch (LabFailure $error) {
            self::assertSame($reason, $error->reason);

            return $error;
        }
        self::fail('Expected laboratory failure: '.$reason);
    }

    protected function events(RunResult $result, string $event): array
    {
        return array_values(array_filter($result->trace, fn ($row) => $row['event'] === $event));
    }

    protected function assertRootCustody(WeakReference $pdo, WeakReference $connection, LabFixture $fixture): void
    {
        gc_collect_cycles();
        self::assertNotNull($pdo->get());
        self::assertNotNull($connection->get());
        self::assertTrue(PrivateConnectionFactory::admissionClosed());
        $this->failure(fn () => new PrivateConnectionFactory($fixture), 'ConnectionInvalidated');
        $post = $fixture->observe('U', false);
        self::assertContains($post['status'], ['UNKNOWN', 'BLOCKED']);
    }

    protected function startWorker(LabFixture $fixture, string $role, string $key = 'synthetic-probe', string $worker = 'holder'): array
    {
        $fds = FaultPlan::assertNoInheritedHandles();
        $script = dirname(__DIR__).'/Support/BookingOwnershipLab/worker.php';
        $arguments = [PHP_BINARY, $script, $role, $fixture->directory, $fixture->hash(), $key, $worker];
        $environment = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'TZ' => 'UTC', 'LANG' => 'C', 'T1_ISOLATED' => '1',
            'T1_RUN_ROOT' => LabFixture::runRoot(), 'T1_SOURCE_MANIFEST' => getenv('T1_SOURCE_MANIFEST') ?: ''];
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        if (! is_resource($process)) {
            throw new LabFailure('WorkerStartFailure', 'PROCESS');
        }
        $fixture->record('processes', ['event' => 'launch', 'supervisor_pid' => getmypid(), 'fds' => $fds, 'argv' => $arguments, 'worker_sha256' => hash_file('sha256', $script)]);

        return [$process, $pipes, $fixture];
    }

    protected function finishWorker(array $worker, int $expectedExit = 0): array
    {
        [$process, $pipes, $fixture] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $fixture->record('processes', ['event' => 'finish', 'exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr]);
        self::assertSame($expectedExit, $exit, $stdout.' '.$stderr);
        self::assertSame('', $stderr);

        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function initializedByWorker(string $case): LabFixture
    {
        $fixture = LabFixture::create($case);
        $result = $this->finishWorker($this->startWorker($fixture, 'init'));
        $fixture->refresh();
        self::assertSame($fixture->hash(), $result['manifest_hash']);

        return $fixture;
    }
}
