<?php

use App\Models\User;
use App\Services\InquiryWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$app = mysqlV81Application();

[$script, $mode, $service, $client] = $argv;
echo "ready\n";
if (trim(fgets(STDIN) ?: '') !== 'go') {
    exit(2);
}
if ($mode === 'open') {
    $result = $app->make(InquiryWorkflow::class)->open(User::findOrFail((int) $client), (int) $service, 'Solicitud simultánea sintética');
    echo json_encode(['id' => $result['inquiry']->id, 'created' => $result['created']], JSON_THROW_ON_ERROR)."\n";
} elseif ($mode === 'insert') {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
    try {
        DB::table('service_inquiries')->insert([
            'service_id' => (int) $service, 'client_user_id' => (int) $client,
            'service_title' => 'Prueba', 'message' => 'Prueba',
        ]);
        echo "{\"inserted\":true}\n";
    } catch (QueryException $e) {
        echo json_encode(['sqlstate' => $e->errorInfo[0], 'diagnostic' => $e->errorInfo[1]], JSON_THROW_ON_ERROR)."\n";
    }
} elseif ($mode === 'deadlock') {
    $attempts = 0;
    $increment = static fn (string $key) => DB::table('cache')->where('key', 'v81-deadlock-'.$key)
        ->update(['value' => DB::raw('CAST(value AS UNSIGNED) + 1')]);
    DB::transaction(function () use (&$attempts, $increment, $service, $client) {
        $increment($service);
        if (++$attempts === 1) {
            echo "locked\n";
            if (trim(fgets(STDIN) ?: '') !== 'cross') {
                throw new RuntimeException('Missing synchronization barrier.');
            }
        }
        $increment($client);
    }, 3);
    echo json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR)."\n";
} else {
    exit(2);
}
