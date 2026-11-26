<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MobileCleanInstallationTest extends TestCase
{
    public function test_artisan_migrates_empty_sqlite_with_default_database_cache_and_clock_on_every_pdo(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'tekora-m31-');
        self::assertNotFalse($database);
        $environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $database.'.config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'DB_FOREIGN_KEYS' => 'true',
            'CACHE_STORE' => 'database',
        ];
        $root = dirname(__DIR__, 2);

        try {
            (new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $root, $environment))->mustRun();

            $probe = <<<'PROBE'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2030-01-01T12:00:00.123456Z'));
            $db = $app->make('db');
            $main = $db->connection();
            $readClock = static fn (PDO $pdo) => $pdo
                ->query('SELECT CAST(tekora_booking_now_us() AS INTEGER)')
                ->fetchColumn();

            $checks = [
                'cache' => $app['config']->get('cache.default'),
                'database' => $main->getDatabaseName(),
                'migrations' => $main->table('migrations')->count(),
                'integrity' => $main->selectOne('PRAGMA integrity_check')->integrity_check,
                'foreign_keys' => $main->selectOne('PRAGMA foreign_keys')->foreign_keys,
                'foreign_key_check' => $main->select('PRAGMA foreign_key_check'),
                'initial_clock' => $readClock($main->getPdo()),
            ];
            $db->reconnect();
            $checks['reconnected_clock'] = $readClock($db->connection()->getPdo());

            $app['config']->set('database.connections.probe', ['read' => [], 'write' => []] + $main->getConfig());
            $probe = $db->connection('probe');
            $checks['separate_read_pdo'] = $probe->getPdo() !== $probe->getReadPdo();
            $checks['new_write_clock'] = $readClock($probe->getPdo());
            $checks['new_read_clock'] = $readClock($probe->getReadPdo());
            echo json_encode($checks, JSON_THROW_ON_ERROR);
            PROBE;
            $result = (new Process([PHP_BINARY, '-r', $probe], $root, $environment))->mustRun();
            self::assertSame([
                'cache' => 'database',
                'database' => $database,
                'migrations' => count(glob($root.'/database/migrations/*.php')) + 1,
                'integrity' => 'ok',
                'foreign_keys' => 1,
                'foreign_key_check' => [],
                'initial_clock' => 1893499200123456,
                'reconnected_clock' => 1893499200123456,
                'separate_read_pdo' => true,
                'new_write_clock' => 1893499200123456,
                'new_read_clock' => 1893499200123456,
            ], json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($database);
        }
    }
}
