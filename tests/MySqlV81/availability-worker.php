<?php

use App\Exceptions\AvailabilityFailure;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$app = mysqlV81Application();
[$script, $mode, $id, $payload] = $argv;
$input = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
$availability = $app->make(ProviderAvailability::class);
$send = static function (array $result): void {
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
};
$wait = static function (string $expected): void {
    if (trim(fgets(STDIN) ?: '') !== $expected) {
        throw new RuntimeException('Missing synchronization barrier.');
    }
};

if ($mode === 'insert') {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::beginTransaction();
    $slot = DB::table('provider_weekly_intervals')->where('provider_profile_id', $id);
    $initialCount = $slot->count();
}

echo "ready\n";
fflush(STDOUT);
$wait('go');

if ($mode === 'save') {
    try {
        $setting = $availability->save(User::findOrFail($id), $input);
        $send(['revision' => $setting->revision, 'timezone' => $setting->timezone]);
    } catch (AvailabilityFailure $exception) {
        $send(['status' => $exception->status]);
    }
} elseif ($mode === 'insert') {
    try {
        foreach (['retry', null] as $next) {
            try {
                DB::table('provider_weekly_intervals')->insert($input);
                $send(['inserted' => true]);
            } catch (QueryException $exception) {
                $send(['code' => (int) $exception->errorInfo[1], 'initial' => $initialCount, 'visible' => $slot->count()]);
            }
            if ($next !== null) {
                $wait($next);
            }
        }
    } finally {
        DB::rollBack();
    }
} elseif ($mode === 'read') {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $profile = ProviderProfile::findOrFail($id);
    $send($availability->publicFor($profile));
    $wait('retry');
    $send($availability->publicFor($profile));
} else {
    exit(2);
}
