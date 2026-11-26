<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('retirement-mysql-w62cb')]
class MySqlRetirementLabTest extends \Illuminate\Foundation\Testing\TestCase
{
    private array $lab;

    private PDO $pdo;

    private int $ddlCount = 0;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath('/tmp');
        $app->loadEnvironmentFrom('w62cb-absent-'.bin2hex(random_bytes(16)));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    private function identity(PDO $pdo, array $lab): array
    {
        // Configuration is checked BEFORE any connection to an unexpected host.
        if (getenv('TEKORA_MYSQL_LAB_ENABLE') !== '1' || ! app()->environment('testing')
            || ! preg_match('/^[a-f0-9]{16}$/D', $lab['run'])
            || $lab['host'] !== 'mysql-lab' || $lab['database'] !== 'w62cblab'.$lab['run']
            || $lab['user'] !== 'lab_'.$lab['run'] || ! $lab['docker_verified']) {
            throw new RuntimeException('BLOCKED: lab identity configuration');
        }
        $server = $pdo->query('SELECT DATABASE() AS db, @@server_uuid AS uuid, @@hostname AS hostname, VERSION() AS version, @@session.foreign_key_checks AS fk, @@default_storage_engine AS engine, @@session.sql_mode AS sql_mode, CURRENT_USER() AS account')->fetch(PDO::FETCH_ASSOC);
        if ($server['db'] !== $lab['database'] || $server['uuid'] !== $lab['server_uuid']
            || $server['hostname'] !== $lab['server_hostname'] || ! str_starts_with($server['version'], '8.4.')
            || (int) $server['fk'] !== 1 || $server['engine'] !== 'InnoDB'
            || ! str_starts_with($server['account'], $lab['user'].'@')) {
            throw new RuntimeException('BLOCKED: unexpected server, database, account or constraints');
        }
        $marker = $pdo->query('SELECT marker FROM w62cb_identity')->fetchAll(PDO::FETCH_COLUMN);
        if (count($marker) !== 1 || ! hash_equals($lab['marker'], $marker[0])) {
            throw new RuntimeException('BLOCKED: unexpected isolation marker');
        }
        // Validate absence of global/other-schema rights without publishing grants.
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (! str_starts_with($grant, 'GRANT USAGE ON *.*')
                && ! str_contains($grant, ' ON `'.$lab['database'].'`.* TO ')) {
                throw new RuntimeException('BLOCKED: privileges outside synthetic database');
            }
        }

        return array_intersect_key($server, array_flip(['uuid', 'hostname', 'version', 'engine', 'sql_mode']));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->lab = json_decode(file_get_contents('/run/tekora-lab.json'), true, flags: JSON_THROW_ON_ERROR);
        // Reject config before creating PDO: no arbitrary DSN or host accepted.
        if ($this->lab['host'] !== 'mysql-lab' || ! preg_match('/^w62cblab[a-f0-9]{16}$/D', $this->lab['database'])) {
            throw new RuntimeException('BLOCKED: configuration before connect');
        }
        try {
            $this->pdo = new PDO('mysql:host=mysql-lab;dbname='.$this->lab['database'].';charset=utf8mb4', $this->lab['user'], $this->lab['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            throw new RuntimeException('BLOCKED: lab connection failed (credentials redacted)');
        }
        $this->identity($this->pdo, $this->lab);
        config(['database.default' => 'lab', 'database.connections.lab' => [
            'driver' => 'mysql', 'host' => 'mysql-lab', 'database' => $this->lab['database'],
            'username' => $this->lab['user'], 'password' => $this->lab['password'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'strict' => true, 'engine' => 'InnoDB',
        ]]);
        DB::purge('lab');
        DB::connection('lab')->setPdo($this->pdo);
        DB::connection('lab')->setReadPdo($this->pdo);
        DB::connection('lab')->beforeExecuting(function ($sql) {
            if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql)) {
                $this->identity($this->pdo, $this->lab);
                $this->ddlCount++;
            }
        });
    }

    public static function wrongIdentity(): array
    {
        return [['host', 'localhost'], ['database', 'production'], ['server_uuid', 'wrong-server'],
            ['server_hostname', 'wrong-host'], ['marker', 'wrong-marker']];
    }

    #[DataProvider('wrongIdentity')]
    public function test_wrong_identity_stops_before_ddl(string $key, string $value): void
    {
        $bad = $this->lab;
        $bad[$key] = $value;
        $before = $this->ddlCount;
        try {
            $this->identity($this->pdo, $bad);
            $this->fail('Unexpected identity accepted');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('BLOCKED:', $e->getMessage());
        }
        $this->assertSame($before, $this->ddlCount);
        $this->assertSame($this->lab['database'], $this->pdo->query('SELECT DATABASE()')->fetchColumn());
    }

    private function installHistorical(?string $file): void
    {
        $this->identity($this->pdo, $this->lab);
        if ($file === null) {
            app('migration.repository')->createRepository();
        } else {
            app('migrator')->run([$file], ['force' => true]);
        }
    }

    public function test_all_37_historical_migrations_must_install_without_edits_or_skips(): void
    {
        $start = microtime(true);
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        $this->assertCount(37, $files);
        $this->assertSame(['w62cb_identity'], Schema::getTableListing(schemaQualified: false));
        $result = ['status' => 'RUNNING', 'server' => $this->identity($this->pdo, $this->lab),
            'worker_php' => PHP_VERSION, 'laravel' => app()->version(), 'applied' => [], 'expected' => 37,
            'retirement_ddl_executed' => false, 'downstream_scenarios' => 'NOT RUN until all 37 migrations pass'];
        $current = null;
        try {
            // All migration DDL goes through this private helper and per-statement guard.
            $this->installHistorical(null);
            foreach ($files as $file) {
                $current = basename($file);
                $this->installHistorical($file);
                $result['applied'][] = $current;
            }
            $result['status'] = 'PASS';
            $this->assertDatabaseCount('migrations', 37);
        } catch (\Throwable $e) {
            $result['status'] = 'BLOCKED';
            $result['failed_migration'] = $current;
            $result['exception_class'] = $e::class;
            $result['reason'] = $e::class === RuntimeException::class ? $e->getMessage() : 'Database exception; sanitized to avoid SQL bindings or credentials';
        } finally {
            $result['seconds'] = round(microtime(true) - $start, 3);
            $result['ddl_statements_verified'] = $this->ddlCount;
            $result['catalog'] = [];
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                $result['catalog'][$table] = ['columns' => Schema::getColumns($table), 'indexes' => Schema::getIndexes($table), 'foreign_keys' => Schema::getForeignKeys($table)];
            }
            $result['table_engines'] = $this->pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
            $result['objects'] = [];
            foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'VIEWS' => 'TABLE_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $column) {
                $result['objects'][$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.' = DATABASE()')->fetchColumn();
            }
            file_put_contents('/tmp/w62cb-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
        $this->assertSame('PASS', $result['status'], 'BLOCKED: historical MySQL install; see sanitized lab evidence. No retirement, fixtures or restore may proceed.');
    }
}
