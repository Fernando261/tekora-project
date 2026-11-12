<?php

declare(strict_types=1);

// Standalone new PHP process. No Laravel boot or environment file discovery.
require dirname(__DIR__, 2).'/payments-b231-bootstrap.php';

use App\Scheduling\Holds\HoldActor;
use Tests\Support\PaymentsIntegration\FixtureClock;
use Tests\Support\PaymentsIntegration\IntegrationBusy;
use Tests\Support\PaymentsIntegration\IntegrationUncertain;
use Tests\Support\PaymentsIntegration\OwnedIntegrationLab;
use Tests\Support\PaymentsIntegration\ScenarioFixture;
use Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork;

function emit(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR)."\n";
    flush();
}
$config = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$lab = OwnedIntegrationLab::resume($config['permit'], $config['token']);
$clock = new FixtureClock;
$clock->us = $config['time'];
$calls = 0;
$unit = new SharedSqliteUnitOfWork($lab, fn () => $clock->now(), fault: static function ($phase) use ($config): void {
    if (($config['lost_ack'] ?? false) && $phase === 'after_commit') {
        throw new RuntimeException('Injected acknowledgement loss');
    }
});
$f = new ScenarioFixture($unit, static function ($stage) use (&$calls, $config): void {
    if ($stage === 'work') {
        $calls++;
    }
    if (($config['pause'] ?? false) && $stage === 'head') {
        emit(['phase' => 'writing', 'pid' => getmypid()]);
        if (trim(fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Expected release barrier');
        }
    }
});
emit(['phase' => 'ready', 'pid' => getmypid()]);
while (in_array(trim(fgets(STDIN)), ['go', 'retry'], true)) {
    try {
        if ($config['operation'] === 'resolve') {
            $intent = ScenarioFixture::intent('accept', HoldActor::provider(101), $config['key'], [$config['proposal'], 1]);
            $r = $unit->resolve($intent);
        } else {
            $r = $f->accept(101, $config['proposal'], 1, $config['key']);
        }
        emit(['phase' => 'result', 'pid' => getmypid(), 'calls' => $calls, 'kind' => $r->kind, 'historical' => $r->historical, 'current' => $r->current, 'busy' => $unit->policy->events]);
        break;
    } catch (IntegrationBusy) {
        emit(['phase' => 'busy', 'pid' => getmypid(), 'calls' => $calls, 'busy' => $unit->policy->events]);
    } catch (IntegrationUncertain) {
        emit(['phase' => 'uncertain', 'pid' => getmypid(), 'calls' => $calls]);
        break;
    }
}
$unit->close();
