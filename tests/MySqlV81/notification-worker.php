<?php

use App\Enums\InquiryAction;
use App\Exceptions\NotificationFailure;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\NotificationInbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/bootstrap.php';
mysqlV81Application();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
Carbon::setTestNow(Carbon::parse('2030-01-10 12:00:00', 'UTC'));
$inbox = app(NotificationInbox::class);
$actor = User::findOrFail($input['actor']);

function command(string $expected): void
{
    if (trim(fgets(STDIN) ?: '') !== $expected) {
        throw new RuntimeException('Missing synchronization barrier.');
    }
}

function report(array $response): void
{
    echo json_encode($response, JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
}

if ($input['mode'] === 'append') {
    echo "ready\n";
    command('go');
    app(InquiryWorkflow::class)->decide($actor, $input['inquiry'], InquiryAction::Accept);
    report(['committed' => true]);
    exit;
}

if ($input['mode'] === 'deadlock') {
    echo "ready\n";
    command('go');
    $attempts = 0;
    $diagnostics = [];
    DB::transaction(function () use ($inbox, $actor, $input, &$attempts, &$diagnostics) {
        $inbox->readActivity($actor, (string) $input['id']);
        if (++$attempts === 1) {
            echo "locked\n";
            fflush(STDOUT);
            command('cross');
        }
        try {
            DB::table('activity_notifications')->where('id', $input['other'])->lockForUpdate()->value('id');
        } catch (QueryException $error) {
            $diagnostics[] = (int) $error->errorInfo[1];
            throw $error;
        }
    }, 3);
    report(['attempts' => $attempts, 'diagnostics' => $diagnostics]);
    exit;
}

DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
$table = $input['source'] === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
$row = DB::table($table)->where('id', $input['id']);
DB::beginTransaction();
try {
    $initial = $row->value('read_at');
    echo "ready\n";
    foreach ($input['retry'] ? ['go', 'retry'] : ['go'] as $message) {
        command($message);
        try {
            $notice = $input['mode'] === 'client' ? $inbox->readClient($actor, $input['source'], (string) $input['id'])
                : $inbox->readActivity($actor, (string) $input['id']);
            $result = ['read_at' => $notice->read_at];
        } catch (NotificationFailure $failure) {
            $result = ['error' => $failure->status, 'safe' => $failure->getPrevious() === null];
        } catch (HttpExceptionInterface $exception) {
            $result = ['error' => $exception->getStatusCode()];
        }
        $result += ['initial' => $initial, 'snapshot' => $row->value('read_at'), 'level' => DB::transactionLevel()];
        if ($message === 'retry' || ! $input['retry']) {
            DB::commit();
        }
        report($result);
    }
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
