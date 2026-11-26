<?php

namespace Tests\MySqlV81;

use App\Database\PricingMySqlConnection;
use App\Exceptions\ReservationStorageUncertain;
use App\Services\MysqlReservationTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BookingFaultTest extends LabTestCase
{
    public static function faults(): iterable
    {
        yield 'BEGIN acknowledgement lost' => ['begin', false, false];
        yield 'COMMIT acknowledgement lost after server commit' => ['commit-after', true, false];
        yield 'COMMIT fails before sending' => ['commit-before', false, false];
        yield 'cleanup fails' => ['rollback', false, true];
    }

    #[DataProvider('faults')]
    public function test_injected_uncertainty_never_reuses_the_connection(string $fault, bool $persisted, bool $cleanup): void
    {
        $config = DB::connection()->getConfig();
        // Reuse the existing PDO fault double with a genuine lab connection.
        $pdo = new PricingFaultPdo('mysql:unix_socket='.$config['unix_socket'].';dbname='.$config['database'],
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET SESSION time_zone='+00:00'");
        $floor = (int) DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
        $pdo->exec('SET @tekora_v81_booking_us='.max($floor + 1, time() * 1000000));
        $pdo->fault = $fault;
        $connection = new PricingMySqlConnection($pdo, $config['database'], '', $config);
        $store = app(MysqlReservationTransactions::class);
        $key = 'booking-injected-'.$fault;
        $calls = 0;
        try {
            try {
                $store->run($connection, function () use ($connection, $key, $fault, &$calls) {
                    $calls++;
                    $connection->table('cache')->insert(['key' => $key, 'value' => 'fixture', 'expiration' => 0]);
                    if ($fault === 'rollback') {
                        throw new RuntimeException('injected primary');
                    }
                });
                $this->fail('Uncertain operation reported success.');
            } catch (ReservationStorageUncertain $error) {
                $this->assertSame($fault === 'rollback' ? 'injected primary' : 'injected '.$fault, $error->primaryError->getMessage());
                $this->assertSame($cleanup ? 'injected rollback' : null, $error->cleanupError?->getMessage());
            }
            $this->assertSame($fault === 'begin' ? 0 : 1, $calls);
            $this->assertSame($persisted, DB::table('cache')->where('key', $key)->exists());
            $this->assertNull($connection->getRawPdo());
            $this->assertSame(in_array($fault, ['begin', 'rollback'], true), $pdo->inTransaction());
            try {
                $connection->selectOne('SELECT 1');
                $this->fail('Uncertain connection was reused.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('invalidada', $error->getMessage());
            }
        } finally {
            $pdo->fault = '';
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
