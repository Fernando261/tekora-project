<?php

use App\Enums\InquiryAction;
use App\Exceptions\InquiryConflict;
use App\Exceptions\ReservationFailure;
use App\Exceptions\ReservationStorageUncertain;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\ReservationTransactions;
use App\Services\ReservationWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$isolation = match ($input['isolation'] ?? 'REPEATABLE READ') {
    'REPEATABLE READ' => 'REPEATABLE READ',
    'READ COMMITTED' => 'READ COMMITTED',
};
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) ($input['timeout'] ?? 10));
DB::statement('SET @tekora_v81_booking_us=?', [$input['time']]);
if (isset($input['after_wait'])) {
    DB::listen(static function (QueryExecuted $query) use ($input) {
        if (str_contains($query->sql, 'from `'.($input['after_wait_on'] ?? 'reservation_hold_clock').'`') && str_ends_with($query->sql, 'for update')) {
            DB::statement('SET @tekora_v81_booking_us=?', [$input['after_wait']]);
        }
    });
}
$actor = isset($input['actor']) ? User::findOrFail($input['actor']) : null;
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$parent = $input['parent'] ?? false;
if ($parent) {
    DB::beginTransaction();
    if ($input['action'] === 'deadlock') {
        DB::table('cache')->insert(['key' => 'booking-deadlock-parent-work', 'value' => 'fixture', 'expiration' => 0]);
    }
    // A real consistent read before entering the adapter/savepoint.
    DB::table('service_reservations')->count();
}

echo "ready\n";
if (trim(fgets(STDIN) ?: '') !== 'go') {
    exit(2);
}
echo json_encode(['connection' => $connection], JSON_THROW_ON_ERROR)."\n";

$calls = 0;
try {
    $workflow = app(ReservationWorkflow::class);
    $result = match ($input['action']) {
        'deadlock' => app(ReservationTransactions::class)->run(function () use (&$calls) {
            $calls++;
            DB::table('cache')->insert(['key' => 'booking-deadlock-inner-work', 'value' => 'fixture', 'expiration' => 0]);
            echo "locked\n";
            if (trim(fgets(STDIN) ?: '') !== 'cross') {
                throw new RuntimeException('Missing cross command.');
            }
            DB::table('cache')->where('key', 'booking-deadlock-locked')->update(['value' => 'other writer']);

            return ['unexpected' => true];
        }),
        'admit' => app(ReservationTransactions::class)->run(function () use (&$calls) {
            $calls++;

            return [];
        }),
        'close' => ['inquiry' => app(InquiryWorkflow::class)->decide($actor, $input['inquiry'], InquiryAction::Complete)->status->value],
        'propose' => ['id' => $workflow->propose($actor, $input['inquiry'], $input['body'])['reservation']->id],
        default => ['state' => $workflow->decide($actor, $input['reservation'], $input['action'], $input['body'])->hold->state],
    };
} catch (ReservationStorageUncertain $error) {
    fwrite(STDERR, json_encode(['primary' => $error->primaryError->getMessage(), 'cleanup' => $error->cleanupError?->getMessage()])."\n");
    if ($parent) {
        DB::rollBack(0);
    }
    $result = ['uncertain' => true];
    $parent = false;
} catch (ReservationFailure $error) {
    $result = ['status' => $error->status, 'native' => $error->storageError instanceof PDOException ? ($error->storageError->errorInfo[1] ?? null) : null];
} catch (AuthorizationException) {
    $result = ['status' => 403, 'native' => null];
} catch (InquiryConflict) {
    $result = ['status' => 409, 'native' => null];
} catch (ValidationException $error) {
    $result = ['validation' => array_keys($error->errors())];
}
$pdo = DB::connection()->getPdo();
$result += ['calls' => $calls, 'physical' => $pdo->inTransaction(), 'level' => DB::transactionLevel()];
if ($parent) {
    if (($result['native'] ?? null) === 1213) {
        try {
            DB::selectOne('SELECT 1');
            $result['blocked_until_parent_ends'] = false;
        } catch (LogicException) {
            $result['blocked_until_parent_ends'] = true;
        }
        DB::rollBack(0);
    } else {
        DB::commit();
    }
    $result['released'] = DB::transactionLevel() === 0 && ! $pdo->inTransaction();
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
