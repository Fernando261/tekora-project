<?php

declare(strict_types=1);

// Dedicated fix1 test protocol; never an application entry point.
require dirname(__DIR__, 2).'/payments-b222-bootstrap.php';

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldReceipt;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\UtcInterval;
use Tests\Support\HoldsSqlite\SqliteHoldBusy;
use Tests\Support\HoldsSqlite\SyntheticHoldDatabase;

function notifyFix(array $message): void
{
    echo json_encode(['pid' => getmypid(), ...$message], JSON_THROW_ON_ERROR),"\n";
    flush();
}
function awaitFix(string $step): void
{
    if (json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR)['step'] !== $step) {
        throw new RuntimeException('Invalid fix1 barrier');
    }
}
try {
    $config = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $db = SyntheticHoldDatabase::resume($config['cap']);
    $mode = $config['mode'];
    $bodyCalls = 0;
    $store = null;
    if ($mode === 'block') {
        $pdo = $db->connect();
        notifyFix(['event' => 'READY']);
        awaitFix('RUN');
        $pdo->exec('BEGIN EXCLUSIVE');
        notifyFix(['event' => 'LOCKED']);
        awaitFix('RELEASE');
        $pdo->exec('ROLLBACK');
        notifyFix(['event' => 'RELEASED']);
        exit(0);
    }
    $observer = static function (string $phase, int $attempt) use (&$bodyCalls) {
        notifyFix(['event' => 'BUSY', 'phase' => $phase, 'attempt' => $attempt, 'body_calls' => $bodyCalls]);
        awaitFix('NEXT');
    };
    if ($mode === 'admission') {
        $store = $db->store($db->connect(), onBusy: $observer);
    }
    notifyFix(['event' => 'READY']);
    awaitFix('RUN');
    while (true) {
        try {
            $store ??= $db->store(onBusy: $observer);
            $hold = $store->atomic(function () use ($store, &$bodyCalls) {
                $bodyCalls++;
                $now = new DateTimeImmutable('2030-01-01T08:00:00.123456Z');
                $store->observeClock($now);
                $at = new DateTimeImmutable('2030-01-01T10:00:00.000001Z');
                $proposal = new ReservationProposal(1, 1, new UtcInterval($at, $at->modify('+1 hour')), new AcceptedQuoteReference('fix1-proposal', 101, 1001, 10, 110, 901));
                $hold = SlotHold::start($proposal, $now);
                $store->insert($hold, new HoldEvent($hold->id, 1, null, HoldState::Held, HoldActor::provider(110), $now));
                $store->remember(new HoldReceipt('provider:110:00000000-0000-4000-8000-000000000001', hash('sha256', 'accept:'.$proposal->fingerprint()), $hold->id));

                return $hold;
            });
            notifyFix(['event' => 'RESULT', 'body_calls' => $bodyCalls, 'hold_id' => $hold->id, 'accepted' => $hold->acceptedAt->format('U.u'), 'expires' => $hold->expiresAt->format('U.u'), 'busy' => $store->busyEvents(), 'connection_busy' => $db->busyEvents()]);
            break;
        } catch (SqliteHoldBusy $busy) {
            notifyFix(['event' => 'EXHAUSTED', 'phase' => $busy->phase, 'attempts' => $busy->attempts, 'body_calls' => $bodyCalls, 'busy' => $store?->busyEvents() ?? $db->busyEvents()]);
            awaitFix('RETRY');
        }
    }
} catch (Throwable $error) {
    notifyFix(['event' => 'ERROR', 'class' => $error::class, 'message' => $error->getMessage()]);
    exit(2);
}
