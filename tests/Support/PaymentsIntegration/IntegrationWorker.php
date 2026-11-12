<?php

declare(strict_types=1);

// Standalone exec runtime. No Laravel boot or environment file discovery.
require dirname(__DIR__, 2).'/payments-b231-bootstrap.php';

use App\Scheduling\Holds\HoldActor;
use Tests\Support\PaymentsIntegration\CommandEnvelope;
use Tests\Support\PaymentsIntegration\FixtureClock;
use Tests\Support\PaymentsIntegration\IntegrationBusy;
use Tests\Support\PaymentsIntegration\IntegrationUncertain;
use Tests\Support\PaymentsIntegration\OwnedIntegrationLab;
use Tests\Support\PaymentsIntegration\ProcessRuntime;
use Tests\Support\PaymentsIntegration\ScenarioFixture;
use Tests\Support\PaymentsIntegration\SessionLease;
use Tests\Support\PaymentsIntegration\SessionSqlExecutor;
use Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork;
use Tests\Support\PaymentsIntegration\WriteSession;

function emit(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR)."\n";
    flush();
}

$unit = null;
$exit = 0;
$calls = 0;
try {
    // Before any SQLite connection: no inherited lab DB, journal or WAL descriptor.
    ProcessRuntime::assertNoSqliteDescriptors();
    emit(['phase' => 'started', 'protocol' => 'b231-exec-v1', 'pid' => getmypid(), 'sqlite_descriptors' => 0]);
    $config = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if ($config['parent_pid'] === getmypid()) {
        throw new LogicException('Fresh worker process required');
    }
    $lab = OwnedIntegrationLab::resume($config['permit'], $config['token']);
    $clock = new FixtureClock;
    $clock->us = $config['time'];
    $unit = new SharedSqliteUnitOfWork($lab, fn () => $clock->now(), fault: static function ($phase) use ($config): void {
        if (($config['lost_ack'] ?? false) && $phase === 'after_commit') {
            throw new RuntimeException('Injected acknowledgement loss');
        }
    });
    $f = new ScenarioFixture($unit, static function ($stage) use (&$calls, $config): void {
        if ($stage === 'work') {
            $calls++;
        }
        if (($config['fail_stage'] ?? null) === $stage) {
            throw new RuntimeException('Injected worker failure');
        }
        if (($config['pause'] ?? false) && $stage === 'head') {
            emit(['phase' => 'writing', 'pid' => getmypid()]);
            if (trim(fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Expected release barrier');
            }
        }
    });
    emit(['phase' => 'ready', 'pid' => getmypid(), 'sqlite_descriptors' => count(ProcessRuntime::sqliteDescriptors())]);
    while (in_array(trim(fgets(STDIN)), ['go', 'retry'], true)) {
        try {
            if ($config['operation'] === 'identity') {
                $foreign = new SessionLease($config['parent_pid'], $config['epoch']);
                $denied = false;
                try {
                    $foreign->assertProcess();
                } catch (LogicException) {
                    $denied = true;
                }
                (new SessionLease(getmypid(), 1))->assertProcess();
                $intent = ScenarioFixture::intent('probe', HoldActor::system(), CommandEnvelope::uuid(), []);
                $executor = new SessionSqlExecutor(static fn () => throw new LogicException('Not a registered executor'), $foreign, $intent, $clock->us);
                $sessionDenied = false;
                try {
                    $unit->assertSession(new WriteSession($executor, $intent, $clock->us));
                } catch (LogicException) {
                    $sessionDenied = true;
                }
                emit(['phase' => 'result', 'pid' => getmypid(), 'foreign_lease_denied' => $denied, 'foreign_session_denied' => $sessionDenied, 'own_process_valid' => true]);
            } else {
                if ($config['operation'] === 'resolve') {
                    $intent = ScenarioFixture::intent('accept', HoldActor::provider(101), $config['key'], [$config['proposal'], 1]);
                    $r = $unit->resolve($intent);
                } elseif ($config['operation'] === 'accept') {
                    $r = $f->accept(101, $config['proposal'], 1, $config['key']);
                } else {
                    throw new LogicException('Unknown worker operation');
                }
                emit(['phase' => 'result', 'pid' => getmypid(), 'calls' => $calls, 'kind' => $r->kind, 'historical' => $r->historical, 'current' => $r->current, 'busy' => $unit->policy->events]);
            }
            break;
        } catch (IntegrationBusy) {
            emit(['phase' => 'busy', 'pid' => getmypid(), 'calls' => $calls, 'busy' => $unit->policy->events]);
        } catch (IntegrationUncertain) {
            emit(['phase' => 'uncertain', 'pid' => getmypid(), 'calls' => $calls]);
            break;
        }
    }
} catch (Throwable $e) {
    $exit = 1;
    emit(['phase' => 'failed', 'pid' => getmypid(), 'calls' => $calls, 'exception' => get_class($e)]);
} finally {
    try {
        $unit?->close(); // Only this fresh process's own PDO; never an inherited handle.
        ProcessRuntime::assertNoSqliteDescriptors();
        emit(['phase' => 'closed', 'pid' => getmypid(), 'clean' => true, 'sqlite_descriptors' => 0]);
    } catch (Throwable) {
        $exit = 2;
        emit(['phase' => 'closed', 'pid' => getmypid(), 'clean' => false, 'sqlite_descriptors' => null]);
    }
}
// Normal PHP shutdown, including destructors. No signal, _exit or destructor bypass.
exit($exit);
