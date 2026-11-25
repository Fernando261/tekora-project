<?php

use App\Models\User;
use App\Payments\CreatePricedService;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$actor = isset($input['actor']) ? User::findOrFail($input['actor']) : null;
if (($input['isolation'] ?? '') === 'READ COMMITTED') {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
if (isset($input['timeout'])) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $input['timeout']);
}
$id = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "ready\n";
if (trim(fgets(STDIN) ?: '') !== 'go') {
    exit(2);
}
echo json_encode(['connection' => $id], JSON_THROW_ON_ERROR)."\n";
$callbacks = 0;
$store = app(PricingStore::class);
try {
    $payload = match ($input['operation']) {
        'publish' => (function () use ($input, $actor) {
            $price = app(ServicePricing::class)->publish($actor, $input['service'], $input['body']);

            return ['id' => $price->id, 'version' => $price->version];
        })(),
        'create' => ['id' => app(CreatePricedService::class)->create($actor, $input['details'], $input['cents'], $input['key'])->id],
        'issue' => (function () use ($input, $actor) {
            $quote = app(SyntheticQuotes::class)->issue($actor, $input['inquiry'], ['idempotency_key' => $input['key']]);

            return ['id' => $quote->id, 'version' => $quote->price->version, 'cents' => $quote->price->amount->totalCents];
        })(),
        'accept' => ['at' => app(SyntheticQuotes::class)->accept($actor, $input['quote'])->acceptedAt->format('Y-m-d H:i:s.u')],
        'admit' => $store->atomic(function () use (&$callbacks) {
            $callbacks++;

            return [];
        }),
        'wait-in-body' => $store->atomic(function () use (&$callbacks) {
            $callbacks++;
            DB::table('cache')->insert(['key' => 'pricing-timeout-partial', 'value' => 'partial', 'expiration' => 0]);
            DB::table('cache')->where('key', 'pricing-timeout-parent')->update(['value' => 'unexpected']);

            return [];
        }),
        'deadlock' => $store->atomic(function () use (&$callbacks) {
            $callbacks++;
            DB::table('cache')->where('key', 'pricing-deadlock-worker')->update(['value' => 'unexpected']);
            echo "locked\n";
            if (trim(fgets(STDIN) ?: '') !== 'cross') {
                throw new RuntimeException('Missing synchronization barrier.');
            }
            DB::table('cache')->where('key', 'pricing-deadlock-parent')->update(['value' => 'unexpected']);

            return [];
        }),
    };
} catch (PricingConflict $e) {
    $previous = $e->getPrevious();
    $payload = ['conflict' => true, 'native' => $previous instanceof PDOException ? $previous->errorInfo[1] : null];
}
$payload += [
    'callbacks' => $callbacks,
    'released' => DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(),
    'same_session' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id === $id,
];
echo json_encode($payload, JSON_THROW_ON_ERROR)."\n";
