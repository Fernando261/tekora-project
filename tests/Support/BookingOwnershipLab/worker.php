<?php

declare(strict_types=1);

use Tests\Support\BookingOwnershipLab\FaultPlan;
use Tests\Support\BookingOwnershipLab\InvocationSpec;
use Tests\Support\BookingOwnershipLab\LabFailure;
use Tests\Support\BookingOwnershipLab\LabFixture;
use Tests\Support\BookingOwnershipLab\OwnershipCoordinator;
use Tests\Support\BookingOwnershipLab\PrivateConnectionFactory;

require dirname(__DIR__, 2).'/BookingOwnershipLab/bootstrap.php';

try {
    if ($argc !== 6 || ! in_array($argv[1], ['init', 'observe', 'writer_lock', 'reader_lock', 'write'], true)
        || ! in_array($argv[5], ['holder', 'left', 'right'], true)) {
        throw new LabFailure('WorkerArgumentsRejected', 'PROCESS');
    }
    FaultPlan::assertNoInheritedHandles();
    $fixture = LabFixture::load($argv[2], $argv[3]);
    $role = $argv[1];
    $output = ['pid' => getmypid(), 'role' => $role, 'php' => PHP_VERSION];
    if ($role === 'init') {
        $fixture->initialize();
        $output['manifest_hash'] = $fixture->hash();
        $output['snapshot'] = $fixture->observe('P');
    } elseif ($role === 'observe') {
        $output['snapshot'] = $fixture->observe('S');
    } elseif (in_array($role, ['writer_lock', 'reader_lock'], true)) {
        $pdo = $fixture->connect(false);
        $pdo->beginTransaction();
        if ($role === 'writer_lock') {
            $pdo->exec('UPDATE coordination SET admission_version=999');
        } else {
            $pdo->query('SELECT * FROM coordination')->fetchAll();
        }
        FaultPlan::signal($fixture, 'ready-holder');
        try {
            FaultPlan::await($fixture, 'release');
        } finally {
            $output['rollback_proven'] = $pdo->rollBack() === true;
            $pdo = null;
            FaultPlan::signal($fixture, 'released');
        }
    } else {
        FaultPlan::signal($fixture, 'ready-'.$argv[5]);
        FaultPlan::await($fixture, 'go-'.$argv[5]);
        $factory = new PrivateConnectionFactory($fixture, (new FaultPlan)->concurrencyBarrier($fixture, $argv[5]));
        $invocation = new InvocationSpec($argv[4], $fixture->epoch());
        $output['result'] = $factory->coordinator()->run($invocation, OwnershipCoordinator::program(...))->jsonSerialize();
        $output['release'] = $factory->auditRelease();
        if ($argv[5] === 'left' && $output['result']['native_outcome'] === 'COMMITTED' && $output['release']['status'] === 'RELEASED') {
            FaultPlan::signal($fixture, 'committed-left');
        }
    }
    echo json_encode($output, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $failure = LabFailure::from($error, 'WORKER')->jsonSerialize();
    if (isset($fixture)) {
        try {
            $fixture->record('processes', ['event' => 'worker_error', 'pid' => getmypid(), 'error' => $failure]);
        } catch (Throwable) {
            // Keep the primary diagnostic on the existing stdout transport.
        }
    }
    echo json_encode(['error' => $failure], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
