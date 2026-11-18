<?php

namespace Tests\Feature;

use App\Payments\PricingConflict;
use App\Payments\PricingUnavailable;
use App\Payments\SqlitePricingStore;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use WeakReference;

class WebPricingStorageTest extends TestCase
{
    private function connection(?PDO $pdo = null): SQLiteConnection
    {
        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE pricing_write_lock(id INTEGER PRIMARY KEY); INSERT INTO pricing_write_lock VALUES(1); CREATE TABLE effects(id INTEGER PRIMARY KEY)');

        return new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite']);
    }

    public static function uncertainCommit(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('uncertainCommit')]
    public function test_commit_failure_never_replays_body_and_invalidates_connection(bool $after): void
    {
        $pdo = new class($after) extends PDO
        {
            public function __construct(private bool $after)
            {
                parent::__construct('sqlite::memory:');
            }

            public function commit(): bool
            {
                if ($this->after) {
                    parent::commit();
                }
                throw new PDOException('injected commit uncertainty');
            }
        };
        $connection = $this->connection($pdo);
        $store = new SqlitePricingStore($connection);
        $calls = 0;
        try {
            $store->atomic(function () use ($pdo, &$calls) {
                $calls++;
                $pdo->exec('INSERT INTO effects VALUES(1)');
            });
            $this->fail('Debe comunicar resultado incierto.');
        } catch (PricingUnavailable $error) {
            $this->assertSame('injected commit uncertainty', $error->getPrevious()->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame($after ? 1 : 0, (int) $pdo->query('SELECT count(*) FROM effects')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
        try {
            $store->atomic(fn () => $this->fail('Reutilizada'));
            $this->fail('Conexión inválida reutilizada.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('no está disponible', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        $connection->select('SELECT 1');
    }

    public function test_cleanup_failure_keeps_primary_error_and_blocks_reads_and_reuse(): void
    {
        $pdo = new class('sqlite::memory:') extends PDO
        {
            public function rollBack(): bool
            {
                throw new PDOException('injected rollback failure');
            }
        };
        $connection = $this->connection($pdo);
        $store = new SqlitePricingStore($connection);
        $primary = new \RuntimeException('primary');
        try {
            $store->atomic(fn () => throw $primary);
            $this->fail('Debe fallar.');
        } catch (PricingUnavailable $error) {
            $this->assertSame($primary, $error->getPrevious());
            $this->assertSame('injected rollback failure', $error->cleanupError->getMessage());
        }
        $this->assertTrue($pdo->inTransaction());
        $reflection = new \ReflectionProperty($store, 'quarantine');
        $this->assertContains($pdo, $reflection->getValue());
        try {
            $store->latestPrice(1);
            $this->fail('No debe leer una conexión incierta.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('no está disponible', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        $connection->select('SELECT 1');
    }

    public function test_exception_after_physical_begin_never_executes_business_and_invalidates_context(): void
    {
        $pdo = new class('sqlite::memory:') extends PDO
        {
            public function beginTransaction(): bool
            {
                parent::beginTransaction();
                throw new PDOException('injected after BEGIN');
            }
        };
        $store = new SqlitePricingStore($this->connection($pdo));
        $calls = 0;
        try {
            $store->atomic(function () use (&$calls) {
                $calls++;
            });
            $this->fail('Debe fallar.');
        } catch (PDOException $error) {
            $this->assertSame('injected after BEGIN', $error->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->expectException(\LogicException::class);
        $store->atomic(fn () => $this->fail('No reutilizar'));
    }

    public function test_busy_writer_admission_runs_no_callback_and_allows_a_later_explicit_retry(): void
    {
        $root = sys_get_temp_dir().'/tekora-pricing-lock-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $path = $root.'/synthetic.sqlite';
        $first = new PDO('sqlite:'.$path);
        $firstConnection = $this->connection($first);
        $second = new PDO('sqlite:'.$path);
        $second->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=1');
        $firstReference = WeakReference::create($first);
        $secondReference = WeakReference::create($second);
        $secondConnection = new SQLiteConnection($second, $path, '', ['driver' => 'sqlite']);
        $store = new SqlitePricingStore($secondConnection);
        $first->beginTransaction();
        $first->exec('UPDATE pricing_write_lock SET id=id');
        $calls = 0;
        try {
            try {
                $store->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('Debe detectar BUSY.');
            } catch (PricingConflict $error) {
                $this->assertSame(5, $error->getPrevious()->errorInfo[1] & 255);
            }
            $this->assertSame(0, $calls);
            $this->assertFalse($second->inTransaction());
            $first->rollBack();
            $store->atomic(function () use (&$calls, $second) {
                $calls++;
                $second->exec('INSERT INTO effects VALUES(1)');
            });
            $this->assertSame(1, $calls);
            $this->assertSame(1, (int) $first->query('SELECT count(*) FROM effects')->fetchColumn());
            $this->assertSame('ok', $second->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $second->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            // Connection and its grammar form a cycle: unset alone leaves PDO open.
            $firstConnection->disconnect();
            $secondConnection->disconnect();
            unset($store, $second, $first);
            $this->assertNull($firstReference->get(), 'First PDO must be released before deleting the SQLite file.');
            $this->assertNull($secondReference->get(), 'Second PDO must be released before deleting the SQLite file.');
            unlink($path);
            rmdir($root);
        }
    }

    public function test_swallowed_nested_failure_cannot_commit_partial_effects(): void
    {
        $connection = $this->connection();
        $pdo = $connection->getPdo();
        $store = new SqlitePricingStore($connection);
        try {
            $store->atomic(function () use ($store, $pdo) {
                $pdo->exec('INSERT INTO effects VALUES(1)');
                try {
                    $store->atomic(fn () => throw new \RuntimeException('nested'));
                } catch (\RuntimeException) {
                }
            });
            $this->fail('No debe confirmar una unidad fallida.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('no puede confirmarse', $error->getMessage());
        }
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM effects')->fetchColumn());
    }
}
