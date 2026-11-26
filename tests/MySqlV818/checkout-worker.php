<?php

use App\Models\User;
use App\Payments\IntegratedCheckout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(sys_get_temp_dir());
$app->loadEnvironmentFrom('tekora-v810-no-env-'.bin2hex(random_bytes(12)));
$app->make(Kernel::class)->bootstrap();
$path = getenv('TEKORA_V818_PRIVATE').'/'.getenv('TEKORA_V818_INSTANCE').'-app.json';
config([
    'checkout.processor' => 'simulated',
    'database.connections.mysql.password' => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['password'],
]);
$server = DB::selectOne('SELECT CONNECTION_ID() AS id, CURRENT_USER() AS actor, DATABASE() AS db, @@skip_networking AS no_tcp');
if ($server->actor !== 'tekora_app@localhost' || $server->db !== 'tekora' || (int) $server->no_tcp !== 1) {
    throw new RuntimeException('El trabajador requiere la base aislada y la cuenta del laboratorio.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
echo $server->id, PHP_EOL;
flush();
$receipt = app(IntegratedCheckout::class)->submit(User::findOrFail($input['actor']), $input['reservation'], $input['selection']);
echo json_encode($receipt, JSON_THROW_ON_ERROR), PHP_EOL;
