<?php

require dirname(__DIR__).'/bootstrap.php';
$directory = $argv[1];
$worker = $argv[2];
if (realpath($directory) !== $directory || ! str_starts_with(basename($directory), 'tekora-demo-race-') || ! in_array($worker, ['1', '2'], true) || is_link($directory.'/input.json')) {
    exit(90);
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['checkout_demo.root' => $input['root']]);
\Illuminate\Support\Carbon::setTestNow(\Carbon\Carbon::parse($input['now'], 'UTC'));
file_put_contents($directory.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        exit(91);
    } usleep(1000);
}
file_put_contents($directory.'/entering-'.$worker, 'entering locked operation');
$store = app(\App\Demo\CheckoutStore::class);
$result = $store->client($input['credential'], fn (&$state) => $store->submit($state, $input['body']));
echo json_encode(['pid' => getmypid(), 'attempt' => $result['attempt']]);
