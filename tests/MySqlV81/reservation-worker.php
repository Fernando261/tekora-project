<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
[$script, $mode, $payload] = $argv;
$input = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

function waitForCommand(string $expected): void
{
    if (trim(fgets(STDIN) ?: '') !== $expected) {
        throw new RuntimeException('Missing synchronization barrier.');
    }
}

function report(array $payload): void
{
    echo json_encode($payload, JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
}

$snapshot = null;
if (in_array($mode, ['insert', 'overlap', 'close'], true)) {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::beginTransaction();
    $reservations = DB::table('service_reservations')->where('provider_profile_id', $input['profile'])->where('status', $input['visible_status']);
    $snapshot = $reservations->count();
}

echo "ready\n";
fflush(STDOUT);
waitForCommand('go');

if ($mode === 'deadlock') {
    $attempts = 0;
    $diagnostics = [];
    DB::transaction(function () use ($input, &$attempts, &$diagnostics) {
        DB::table('provider_profiles')->where('id', $input['row']['provider_profile_id'])->lockForUpdate()->sole();
        if (++$attempts === 1) {
            echo "locked\n";
            fflush(STDOUT);
            waitForCommand('cross');
        }
        try {
            DB::table('provider_profiles')->where('id', $input['other_profile'])->lockForUpdate()->value('id');
            DB::table('service_reservations')->insert($input['row']);
        } catch (QueryException $error) {
            $diagnostics[] = (int) $error->errorInfo[1];
            throw $error;
        }
    }, 3);
    report(['attempts' => $attempts, 'diagnostics' => $diagnostics]);
} elseif ($mode === 'version') {
    $changed = DB::transaction(fn () => DB::table('service_reservations')->where('id', $input['id'])->where('version', 1)->update($input['patch']), 3);
    report(['changed' => $changed]);
} else {
    try {
        foreach (['retry', null] as $next) {
            try {
                $affected = match ($mode) {
                    'insert' => DB::table('service_reservations')->insert($input['row']),
                    'overlap' => DB::table('service_reservations')->where('id', $input['id'])->update($input['patch']),
                    'close' => DB::table('service_inquiries')->where('id', $input['id'])->update(['status' => 'completed']),
                };
                report(['changed' => $affected]);
            } catch (QueryException $error) {
                $code = (int) $error->errorInfo[1];
                report(['code' => $code, 'diagnostic' => $code === 1644 ? $error->errorInfo[2] : null,
                    'snapshot' => $snapshot, 'visible' => $reservations->count()]);
            }
            if ($next !== null) {
                waitForCommand($next);
            }
        }
    } finally {
        DB::rollBack();
    }
}
