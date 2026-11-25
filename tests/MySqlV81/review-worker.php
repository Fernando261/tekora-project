<?php

use App\Exceptions\ReviewFailure;
use App\Models\ServiceReview;
use App\Models\User;
use App\Services\ReviewWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$isolation = match ($input['isolation']) {
    'REPEATABLE READ' => 'REPEATABLE READ',
    'READ COMMITTED' => 'READ COMMITTED',
};

Carbon::setTestNow(Carbon::parse('2030-01-10 12:00:00', 'UTC'));
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
$workflow = app(ReviewWorkflow::class);
$readState = fn () => ServiceReview::where('service_reservation_id', $input['reservation'])->value('status')?->value;
DB::beginTransaction();
try {
    $initial = $readState();
    $actor = User::findOrFail($input['actor']);
    echo "ready\n";
    foreach ($input['retry'] ? ['go', 'retry'] : ['go'] as $command) {
        if (trim(fgets(STDIN) ?: '') !== $command) {
            throw new RuntimeException('Missing synchronization barrier.');
        }
        try {
            if ($input['action'] === 'publish') {
                $publication = $workflow->publish($actor, $input['reservation'], $input['content']);
                $review = $publication['review'];
                $response = ['created' => $publication['created']];
            } else {
                $review = $workflow->withdraw($actor, $input['review']);
                $response = [];
            }
            $response += ['id' => $review->id, 'status' => $review->status->value, 'withdrawn_at' => $review->withdrawn_at_utc];
        } catch (ReviewFailure $failure) {
            $response = ['error' => $failure->status, 'sanitized' => $failure->getPrevious() === null && ! str_contains($failure->getMessage(), 'SQL')];
        }
        $response += ['initial' => $initial, 'snapshot' => $readState(), 'level' => DB::transactionLevel()];
        if ($command === 'retry' || ! $input['retry']) {
            DB::commit();
        }
        echo json_encode($response, JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
    }
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
