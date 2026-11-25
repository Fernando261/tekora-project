<?php

use App\Models\User;
use App\Payments\CreatePricedService;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$actor = isset($input['actor']) ? User::findOrFail($input['actor']) : null;
if (isset($input['isolation'])) {
    $isolation = match ($input['isolation']) {
        'READ COMMITTED' => 'READ COMMITTED',
        'REPEATABLE READ' => 'REPEATABLE READ',
    };
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
}
if (isset($input['clock'])) {
    Carbon::setTestNow(Carbon::parse($input['clock'], 'UTC'));
    DB::listen(static function (QueryExecuted $query) use ($input) {
        if (str_contains($query->sql, 'from `services`') && str_ends_with($query->sql, 'lock in share mode')) {
            Carbon::setTestNow(Carbon::parse($input['after_lock'], 'UTC'));
        }
    });
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
        'ineligible' => (function () use ($input) {
            $mutate = fn () => match ($input['change']) {
                'pause' => DB::table('services')->where('id', $input['target'])->update(['status' => 'paused']),
                'category' => DB::table('service_categories')->where('id', $input['target'])->update(['is_active' => false]),
                'role' => DB::table('role_user')->where('user_id', $input['target'])->where('role_id', $input['role'])->delete(),
            };
            try {
                return ['rows' => $mutate()];
            } catch (QueryException $error) {
                if (array_slice($error->errorInfo ?? [], 0, 2) !== ['HY000', 1205]) {
                    throw $error;
                }
                echo "{\"native\":1205}\n";
                if (trim(fgets(STDIN) ?: '') !== 'retry') {
                    throw new RuntimeException('Missing retry barrier.');
                }

                return ['rows' => $mutate()];
            }
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
} catch (AuthorizationException|ModelNotFoundException $e) {
    $payload = ['denied' => $e instanceof AuthorizationException ? 403 : 404];
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
