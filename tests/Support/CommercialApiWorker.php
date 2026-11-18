<?php

// Fresh process against a newly cloned synthetic database; never an existing app DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';
[$script, $root, $worker] = $argv;
if (realpath($root) !== $root || ! str_starts_with(basename($root), 'tekora-b3-race-')
    || ! in_array($worker, ['1', '2'], true) || ! is_file($root.'/synthetic-only')
    || ! is_file($root.'/synthetic.sqlite') || is_link($root.'/synthetic.sqlite') || is_link($root.'/private.json')) {
    exit(90);
}
$data = json_decode(file_get_contents($root.'/private.json'), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    exit(91);
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/synthetic.sqlite',
    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 3000]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Carbon::setTestNow(\Carbon\Carbon::parse($data['clock'], 'UTC'));
file_put_contents($root.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($root.'/go')) {
    if (microtime(true) > $deadline) {
        exit(92);
    }
    usleep(1000);
}
$input = $data['workers'][$worker];
$request = \Illuminate\Http\Request::create($input['path'], 'POST', [], [], [],
    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$data['token']], json_encode($input['body']));
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'json' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
