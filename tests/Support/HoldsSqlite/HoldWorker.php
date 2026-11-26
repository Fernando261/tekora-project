<?php

declare(strict_types=1);

// Standalone test worker: never an Artisan command, controller or production entry point.
require dirname(__DIR__, 2).'/payments-b222-bootstrap.php';

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldClock;
use App\Scheduling\Holds\HoldTime;
use App\Scheduling\Holds\HoldViolation;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SyntheticHoldEngine;
use App\Scheduling\Holds\UtcInterval;
use Tests\Support\HoldsSqlite\SqliteHoldBusy;
use Tests\Support\HoldsSqlite\SyntheticHoldDatabase;

function signal(array $value): void
{
    echo json_encode(['pid' => getmypid(), ...$value], JSON_THROW_ON_ERROR),"\n";
    flush();
}
function phase(string $expected): void
{
    $line = fgets(STDIN);
    if ($line === false || json_decode($line, true, flags: JSON_THROW_ON_ERROR)['phase'] !== $expected) {
        throw new RuntimeException('Unexpected coordinator phase.');
    }
}
try {
    $config = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $lab = SyntheticHoldDatabase::resume($config['ticket']);
    $clock = new class($config) implements HoldClock
    {
        private bool $first = true;

        public function __construct(private readonly array $config) {}

        public function now(): DateTimeImmutable
        {
            if ($this->first && ($this->config['pause'] ?? false)) {
                $this->first = false;
                signal(['phase' => 'LOCK_ACQUIRED']);
                phase('CONTINUE');
            }

            return new DateTimeImmutable($this->config['time'] ?? '2030-01-01T08:00:00.123456Z');
        }
    };
    $mode = $config['mode'] ?? 'normal';
    $store = $lab->store(attempts: 1, afterCommit: $mode === 'exit_after' ? static function () {
        signal(['phase' => 'COMMITTED']);
        exit(0);
    } : null, beforeCommit: $mode === 'exit_before' ? static function () {
        signal(['phase' => 'BEFORE_COMMIT']);
        exit(0);
    } : null);
    $engine = new SyntheticHoldEngine($store, $clock);
    signal(['phase' => 'READY']);
    phase('RUN');
    while (true) {
        try {
            $actor = HoldActor::provider($config['provider_user'] ?? 110);
            $command = $config['command'] ?? 'accept';
            if ($command === 'accept') {
                $id = $config['reservation'] ?? 1;
                $profile = $config['provider'] ?? 10;
                $at = new DateTimeImmutable('2030-01-01T'.($config['start'] ?? '10:00:00.000001').'Z');
                $proposal = new ReservationProposal($id, 1, new UtcInterval($at, $at->modify('+1 hour')), new AcceptedQuoteReference($config['quote'] ?? 'quote-'.$id, 100 + $id, 1000 + $id, $profile, $config['provider_user'] ?? $profile + 100, 900 + $id));
                $hold = $engine->accept($proposal, $actor, $config['key']);
            } elseif ($command === 'commit') {
                $hold = $engine->commit($config['hold_id'], HoldActor::system(), $config['version'] ?? 1, $config['key']);
            } else {
                $hold = $engine->release($config['hold_id'], HoldActor::client(901), $config['version'] ?? 1, $config['key']);
            }
            signal(['phase' => 'RESULT', 'outcome' => $hold->state->value, 'hold_id' => $hold->id, 'version' => $hold->version, 'accepted_at' => HoldTime::text($hold->acceptedAt), 'expires_at' => HoldTime::text($hold->expiresAt), 'busy' => $store->busyEvents()]);
            break;
        } catch (SqliteHoldBusy $busy) {
            signal(['phase' => 'BUSY', 'sqlite_phase' => $busy->phase, 'attempts' => $busy->attempts]);
            phase('RETRY');
        } catch (HoldViolation $error) {
            signal(['phase' => 'RESULT', 'outcome' => $error->reason, 'busy' => $store->busyEvents()]);
            break;
        }
    }
} catch (Throwable $error) {
    signal(['phase' => 'ERROR', 'class' => $error::class, 'message' => $error->getMessage()]);
    exit(2);
}
