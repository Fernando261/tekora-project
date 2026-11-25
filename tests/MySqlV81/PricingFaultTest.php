<?php

namespace Tests\MySqlV81;

use App\Database\PricingMySqlConnection;
use App\Payments\MysqlPricingStore;
use App\Payments\PricingUnavailable;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class PricingFaultTest extends LabTestCase
{
    public static function faults(): iterable
    {
        yield 'BEGIN started physically, then acknowledgement lost' => ['begin', false, false];
        yield 'COMMIT reached server, acknowledgement lost' => ['commit-after', true, false];
        yield 'COMMIT failed before reaching server' => ['commit-before', false, false];
        yield 'rollback failed before reaching server' => ['rollback', false, true];
    }

    #[DataProvider('faults')]
    public function test_injected_uncertainty_retires_the_connection_and_preserves_evidence(string $fault, bool $persisted, bool $cleanupError): void
    {
        $config = DB::connection()->getConfig();
        $pdo = new PricingFaultPdo('mysql:unix_socket='.$config['unix_socket'].';dbname='.$config['database'], $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->fault = $fault;
        $connection = new PricingMySqlConnection($pdo, $config['database'], '', $config);
        $store = new MysqlPricingStore($connection);
        $key = 'pricing-injection-'.$fault;
        $calls = 0;
        try {
            try {
                $store->atomic(function () use ($connection, $fault, $key, &$calls) {
                    $calls++;
                    $connection->table('cache')->insert(['key' => $key, 'value' => 'fixture', 'expiration' => 0]);
                    if ($fault === 'rollback') {
                        throw new RuntimeException('injected primary');
                    }
                });
                $this->fail('An uncertain transaction reported a successful result.');
            } catch (PricingUnavailable $e) {
                $this->assertSame($fault === 'rollback' ? 'injected primary' : 'injected '.$fault, $e->getPrevious()->getMessage());
                $this->assertSame($cleanupError ? 'injected rollback' : null, $e->cleanupError?->getMessage());
            }

            $this->assertSame($fault === 'begin' ? 0 : 1, $calls);
            $this->assertSame(in_array($fault, ['begin', 'rollback'], true), $pdo->inTransaction());
            $this->assertSame($persisted, DB::table('cache')->where('key', $key)->exists());
            $this->assertNull($connection->getRawPdo());
            foreach ([fn () => $store->atomic(fn () => $this->fail('Retired store executed callback.')), fn () => $connection->selectOne('SELECT 1')] as $reuse) {
                try {
                    $reuse();
                    $this->fail('Retired connection was reused.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('invalidad', $error->getMessage());
                }
            }
        } finally {
            // Test-only teardown relinquishes server locks held by the fault double.
            $pdo->fault = '';
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}

final class PricingFaultPdo extends PDO
{
    public string $fault = '';

    public function beginTransaction(): bool
    {
        $started = parent::beginTransaction();
        if ($this->fault === 'begin') {
            throw new RuntimeException('injected begin');
        }

        return $started;
    }

    public function commit(): bool
    {
        if ($this->fault === 'commit-before') {
            throw new RuntimeException('injected commit-before');
        }
        $committed = parent::commit();
        if ($this->fault === 'commit-after') {
            throw new RuntimeException('injected commit-after');
        }

        return $committed;
    }

    public function rollBack(): bool
    {
        if ($this->fault === 'rollback') {
            throw new RuntimeException('injected rollback');
        }

        return parent::rollBack();
    }
}
