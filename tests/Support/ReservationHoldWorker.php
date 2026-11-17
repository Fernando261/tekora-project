<?php

// Executed as a fresh PHP process, never fork a process with a live SQLite transaction.
require dirname(__DIR__, 2).'/vendor/autoload.php';
[$script, $root, $worker, $actorId, $reservationId, $action, $key, $clock] = $argv;
if (realpath($root) !== $root || ! str_starts_with(basename($root), 'tekora-hold-race-')
    || ! is_file($root.'/synthetic-only') || ! is_file($root.'/synthetic.sqlite') || is_link($root.'/synthetic.sqlite') || ! ctype_digit($worker)) {
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
\Illuminate\Support\Carbon::setTestNow(\Carbon\Carbon::parse($clock, 'UTC'));
$actor = \App\Models\User::findOrFail((int) $actorId);
file_put_contents($root.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($root.'/go')) {
    if (microtime(true) > $deadline) {
        exit(93);
    } usleep(1000);
}
try {
    $reservation = app(\App\Services\ReservationWorkflow::class)->decide($actor, (int) $reservationId, $action, ['version' => 1, 'idempotency_key' => $key]);
    echo json_encode(['outcome' => $reservation->effectiveStatus()->value, 'id' => $reservation->id, 'pid' => getmypid()]);
} catch (\App\Exceptions\ReservationFailure $error) {
    echo json_encode(['outcome' => 'conflict', 'status' => $error->status, 'pid' => getmypid()]);
}
