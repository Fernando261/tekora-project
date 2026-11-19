<?php

namespace Tests\Feature;

final class LocalIntegratedCheckoutFixtureExportTest extends \Tests\TestCase
{
    use \Tests\Support\SlotHoldFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-b32-no-env-'.bin2hex(random_bytes(16)));

        if (is_file($app->environmentFilePath())) {
            throw new \RuntimeException('No debe leerse ningún archivo .env.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function test_exports_private_sqlite_only_when_requested_by_the_isolated_launcher(): void
    {
        $output = getenv('TEKORA_B32_FIXTURE_OUTPUT');
        if ($output === false || $output === '') {
            // The ordinary PHPUnit suite must never export files or credentials.
            $this->assertTrue(true);

            return;
        }

        $root = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($output));
        if ($root === false || $parent !== $root
            || ! preg_match('/^tekora-b32-report-[a-f0-9]{24}\\.json$/D', basename($output))
            || file_exists($output) || is_link($output)) {
            throw new \RuntimeException('Ruta de exportación sintética no autorizada.');
        }

        $fixture = $this->export();
        $json = json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($output, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('No se pudo entregar el manifiesto sintético.');
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($output, 0600);
        }

        $this->assertFileExists($fixture['database']);
        $this->assertFileExists(dirname($fixture['database']).DIRECTORY_SEPARATOR.'.tekora-b32-synthetic');
    }

    public function export(): array
    {
        $client = $this->account(\App\Enums\RoleName::Client);
        $provider = $this->account(\App\Enums\RoleName::Client, \App\Enums\RoleName::Provider);
        $clientPassword = bin2hex(random_bytes(10));
        $providerPassword = bin2hex(random_bytes(10));
        $client->update(['name' => 'Lía · Cliente Tekora', 'email' => 'cliente@tekora.test', 'password' => $clientPassword]);
        $provider->update(['name' => 'Alex · Prestador Tekora', 'email' => 'prestador@tekora.test', 'password' => $providerPassword]);

        // Do not reuse pricedProposal() here: that test fixture time-travels
        // to 2030. The live Windows server must observe this hold as current.
        $inquiry = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
        $this->publishSchedule($provider);
        app(\App\Payments\ServicePricing::class)->publish(
            $provider, $inquiry->service_id,
            ['amount_cents' => 100000, 'expected_version' => 0,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]
        );
        $quote = app(\App\Payments\SyntheticQuotes::class)->issue(
            $client, $inquiry->id,
            ['idempotency_key' => (string) \Illuminate\Support\Str::uuid()]
        );
        app(\App\Payments\SyntheticQuotes::class)->accept($client, $quote->id);
        $date = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
        $reservation = app(\App\Services\ReservationWorkflow::class)->propose(
            $client, $inquiry->id,
            $this->reservationData(['quote_id' => $quote->id, 'date' => $date, 'time' => '12:00'])
        )['reservation'];
        $provider->providerProfile->update([
            'display_name' => 'Estudio Nodo',
            'headline' => 'Diseño y soporte digital',
            'bio' => 'Prestador sintético para mostrar la contratación completa en Tekora.',
        ]);
        $inquiry->service->update([
            'title' => 'Asesoría digital para tu proyecto',
            'description' => 'Una sesión para revisar tu proyecto y acordar sus siguientes pasos.',
        ]);
        app(\App\Services\ReservationWorkflow::class)->decide(
            $provider, $reservation->id, 'confirm', $this->holdInput()
        );

        // A second, untouched inquiry demonstrates the earlier client-provider journey.
        $pending = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Pending);
        $pending->service->update(['title' => 'Diseño de una presentación']);
        app(\App\Payments\ServicePricing::class)->publish(
            $provider, $pending->service_id,
            ['amount_cents' => 175000, 'expected_version' => 0,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]
        );

        // This table exists only in this disposable SQLite export, never in migrations.
        \Illuminate\Support\Facades\DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY,
            client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL,
            quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card', 'cash')),
            scenario TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('test_approved', 'declined', 'temporary_failure', 'awaiting_cash', 'cash_received')),
            idempotency_key TEXT NOT NULL,
            reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL,
            received_at TEXT NULL
        )");
        $root = realpath(sys_get_temp_dir());
        if ($root === false) {
            throw new \RuntimeException('No se pudo resolver el directorio temporal.');
        }

        $dir = $root.DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));
        if (mkdir($dir, 0700) === false || is_link($dir)) {
            throw new \RuntimeException('No se pudo crear un directorio privado nuevo.');
        }

        $db = $dir.DIRECTORY_SEPARATOR.'tekora.sqlite';
        // VACUUM INTO reads only the in-memory test connection and creates a NEW file.
        $pdo = \Illuminate\Support\Facades\DB::connection('sqlite')->getPdo();
        $pdo->exec('VACUUM main INTO '.$pdo->quote($db));

        if (is_file($db) === false || filesize($db) < 1024) {
            throw new \RuntimeException('La exportación SQLite no terminó.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($db, 0600);
        }

        $check = new \PDO('sqlite:'.$db);

        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException('La nueva SQLite no pasó integrity_check.');
        }

        if (file_put_contents($dir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "TEKORA-B32-SYNTHETIC\n", LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo etiquetar la fixture nueva.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($dir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', 0600);
        }

        return [
            'database' => realpath($db),
            'client_email' => $client->email,
            'client_password' => $clientPassword,
            'provider_email' => $provider->email,
            'provider_password' => $providerPassword,
            'held_inquiry_id' => $inquiry->id,
            'held_reservation_id' => $reservation->id,
            'pending_inquiry_id' => $pending->id,
            'hold_seconds' => 900,
        ];
    }
}
