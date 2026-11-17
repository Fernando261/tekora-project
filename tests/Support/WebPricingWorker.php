<?php

// A child process for the real SQLite race test, exclusively on its disposable copy.
require dirname(__DIR__, 2).'/vendor/autoload.php';
[$script, $root, $actorId, $serviceId, $key, $worker] = $argv;
if (realpath($root) !== $root || ! str_starts_with(basename($root), 'tekora-pricing-race-')
    || ! is_file($root.'/synthetic-only') || ! ctype_digit($worker)) {
    exit(90);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage());
    exit(91);
});
if (! $app->environment('testing') || $app->configurationIsCached()) {
    exit(92);
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/synthetic.sqlite', 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 3000]);
\Illuminate\Support\Facades\DB::purge('sqlite');
$actor = \App\Models\User::findOrFail((int) $actorId);
file_put_contents($root.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($root.'/go')) {
    if (microtime(true) > $deadline) {
        exit(93);
    } usleep(1000);
}
try {
    $price = app(\App\Payments\ServicePricing::class)->publish($actor, (int) $serviceId, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => $key]);
    echo json_encode(['outcome' => 'created', 'id' => $price->id]);
} catch (\App\Payments\PricingConflict $error) {
    echo json_encode(['outcome' => 'conflict']);
}
