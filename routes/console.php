<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * One-time, explicit setup for SYNTHETIC checkout on the LOCAL app only.
 * Never included in application migrations and never run automatically.
 * Makes an SQLite snapshot before adding the isolated attempt table.
 */
Artisan::command('tekora:local-demo-payments-setup', function () {
    $database = config('database.connections.sqlite.database');
    $path = is_string($database) ? realpath($database) : false;
    $main = realpath(database_path('database.sqlite'));
    if (! app()->environment('local') || app()->configurationIsCached()
        || config('database.default') !== 'sqlite'
        || config('tekora_checkout.local_demo_payments') !== true
        || $path === false || $main === false || $path !== $main) {
        $this->error('Solo funciona con la base principal SQLite de un entorno local y TEKORA_LOCAL_DEMO_PAYMENTS=true.');
        return 1;
    }

    if (\\Illuminate\\Support\\Facades\\Schema::hasTable('synthetic_checkout_attempts')) {
        $this->info('La tabla de intentos ficticios ya existe. No se modifico nada.');
        return 0;
    }

    $backup = database_path('backup-before-demo-payments-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite');
    try {
        $pdo = \\Illuminate\\Support\\Facades\\DB::connection('sqlite')->getPdo();
        // SQLite online backup, including committed WAL content.
        $pdo->exec('VACUUM main INTO '.$pdo->quote($backup));
        if (! is_file($backup) || filesize($backup) < 1024) {
            throw new \\RuntimeException('No se pudo verificar el respaldo SQLite.');
        }
        \\Illuminate\\Support\\Facades\\DB::statement(<<<'SQL'
CREATE TABLE synthetic_checkout_attempts (
  reservation_id INTEGER PRIMARY KEY REFERENCES service_reservations(id) ON DELETE RESTRICT,
  client_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  provider_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  quote_id TEXT NOT NULL REFERENCES service_quotes(id) ON DELETE RESTRICT,
  amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
  method TEXT NOT NULL CHECK(method IN ('card','cash')),
  scenario TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
  idempotency_key TEXT NOT NULL,
  reference TEXT NOT NULL UNIQUE,
  attempted_at TEXT NOT NULL,
  received_at TEXT NULL
)
SQL);
    } catch (\\Throwable $error) {
        $this->error('Configuracion no completada; verifica permisos y SQLite. No se borro ningun dato.');
        return 1;
    }

    $this->info('Listo: intentos de pago ficticios habilitados solo en localhost.');
    $this->line('Respaldo previo: '.$backup);
    $this->warn('Ningun intento simulado es un cobro bancario real.');
    return 0;
})->purpose('Prepare local-only synthetic checkout with a SQLite backup');
