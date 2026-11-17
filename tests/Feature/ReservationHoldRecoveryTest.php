<?php

namespace Tests\Feature;

use App\Exceptions\ReservationFailure;
use App\Exceptions\ReservationStorageUncertain;
use App\Services\ReservationTransactions;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FaultyBookingPdo;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ReservationHoldRecoveryTest extends TestCase
{
    use SlotHoldFixtures;

    private function sharedContext(): array
    {
        $uri = 'file:tekora-hold-recovery-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        config(['database.connections.hold_recovery' => ['driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('hold_recovery');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'hold_recovery', '--force' => true]));
        $context = $this->pricedProposal();
        $pdo = new FaultyBookingPdo('sqlite:'.$uri);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');
        BookingClock::install($pdo);
        DB::connection()->setPdo($pdo);
        $reader = new PDO('sqlite:'.$uri);
        $reader->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $reader->exec('PRAGMA foreign_keys=ON');
        BookingClock::install($reader);

        return [$context, $pdo, $reader];
    }

    public static function commitPhases(): array
    {
        return [['before'], ['after']];
    }

    #[DataProvider('commitPhases')]
    public function test_uncertain_commit_is_resolved_by_receipt_from_a_fresh_connection_without_renewing(string $phase): void
    {
        [[$client, $provider, , , $reservation], $pdo, $reader] = $this->sharedContext();
        $pdo->commitFault = $phase;
        $input = $this->holdInput();
        try {
            app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $input);
            $this->fail('No afirmar éxito con COMMIT incierto.');
        } catch (ReservationStorageUncertain $error) {
            $this->assertSame('Injected uncertain COMMIT', $error->primaryError->getMessage());
        }
        $this->assertSame(1, $pdo->commitCalls);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($phase === 'after' ? 1 : 0, (int) $reader->query('SELECT count(*) FROM reservation_holds')->fetchColumn());
        $before = $reader->query('SELECT accepted_at_us,expires_at_us FROM reservation_holds')->fetch(PDO::FETCH_ASSOC);
        $oldConnection = DB::connection();
        try {
            $oldConnection->select('SELECT 1');
            $this->fail('No reutilizar conexión invalidada.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('incierta', $error->getMessage());
        }
        $this->travel(100)->seconds();
        DB::purge('hold_recovery');
        $retry = app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $input);
        $this->assertSame('held', $retry->hold->state);
        $this->assertDatabaseCount('reservation_holds', 1);
        $this->assertDatabaseCount('reservation_action_receipts', 1);
        $this->assertDatabaseCount('reservation_hold_events', 1);
        if ($phase === 'after') {
            $this->assertSame((int) $before['accepted_at_us'], $retry->hold->accepted_at_us);
            $this->assertSame((int) $before['expires_at_us'], $retry->hold->expires_at_us);
            $this->assertSame(800, $retry->hold->remainingSeconds());
        }
        $this->assertHoldIntegrity();
    }

    public function test_exception_after_physical_begin_runs_no_callback_and_retires_connection(): void
    {
        [, $pdo, $reader] = $this->sharedContext();
        $pdo->beginFault = true;
        $calls = 0;
        try {
            app(ReservationTransactions::class)->run(function () use (&$calls) {
                $calls++;
            });
            $this->fail('BEGIN incierto.');
        } catch (ReservationStorageUncertain $error) {
            $this->assertSame('Injected failure after physical BEGIN', $error->primaryError->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->assertTrue($pdo->inTransaction());
        $this->assertSame(0, (int) $reader->query('SELECT count(*) FROM reservation_holds')->fetchColumn());
        $this->expectException(\LogicException::class);
        DB::connection()->select('SELECT 1');
    }

    public function test_failed_cleanup_preserves_primary_and_secondary_and_quarantines_pdo(): void
    {
        [, $pdo] = $this->sharedContext();
        $pdo->rollbackFault = true;
        $primary = new \RuntimeException('Injected primary failure');
        try {
            app(ReservationTransactions::class)->run(fn () => throw $primary);
            $this->fail('Cleanup incierto.');
        } catch (ReservationStorageUncertain $error) {
            $this->assertSame($primary, $error->primaryError);
            $this->assertSame('Injected cleanup failure', $error->cleanupError->getMessage());
        }
        $this->assertTrue($pdo->inTransaction());
        $this->assertContains($pdo, (new \ReflectionProperty(ReservationTransactions::class, 'quarantine'))->getValue());
        $calls = 0;
        try {
            app(ReservationTransactions::class)->run(function () use (&$calls) {
                $calls++;
            });
            $this->fail('Handle incierto reutilizado.');
        } catch (ReservationFailure $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame(0, $calls);
    }

    public function test_structural_sqlite_one_is_preserved_and_only_own_callback_is_rolled_back(): void
    {
        $calls = 0;
        try {
            app(ReservationTransactions::class)->run(function () use (&$calls) {
                $calls++;
                DB::table('service_categories')->insert(['name' => 'Rollback', 'slug' => 'hold-rollback', 'is_active' => 1]);
                DB::select('SELECT missing FROM no_such_table');
            });
            $this->fail('No ocultar error estructural.');
        } catch (PDOException $error) {
            $this->assertSame(1, $error->errorInfo[1]);
        }
        $this->assertSame(1, $calls);
        $this->assertDatabaseMissing('service_categories', ['slug' => 'hold-rollback']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertHoldIntegrity();
    }

    public function test_manual_and_raw_pdo_transactions_are_rejected_without_commit_or_rollback(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (['pdo', 'manual'] as $kind) {
            if ($kind === 'pdo') {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE');
            }
            $pdo->exec("INSERT INTO service_categories(name,slug,is_active) VALUES('External','hold-external-$kind',1)");
            $called = 0;
            try {
                app(ReservationTransactions::class)->run(function () use (&$called) {
                    $called++;
                });
                $this->fail('Transacción externa adoptada.');
            } catch (ReservationFailure $error) {
                $this->assertSame(503, $error->status);
            }
            $this->assertSame(0, $called);
            $this->assertDatabaseHas('service_categories', ['slug' => 'hold-external-'.$kind]);
            if ($kind === 'pdo') {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK');
            }
            $this->assertDatabaseMissing('service_categories', ['slug' => 'hold-external-'.$kind]);
        }
        $this->assertHoldIntegrity();
    }

    public function test_managed_parent_owns_commit_and_rollback_and_only_our_savepoint_is_cleaned(): void
    {
        DB::beginTransaction();
        DB::table('service_categories')->insert(['name' => 'Parent', 'slug' => 'parent-owned', 'is_active' => 1]);
        try {
            try {
                app(ReservationTransactions::class)->run(function () {
                    DB::table('service_categories')->insert(['name' => 'Child', 'slug' => 'child-owned', 'is_active' => 1]);
                    throw new \RuntimeException('Child failure');
                });
                $this->fail('Debe fallar el savepoint.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Child failure', $error->getMessage());
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertDatabaseHas('service_categories', ['slug' => 'parent-owned']);
            $this->assertDatabaseMissing('service_categories', ['slug' => 'child-owned']);
            app(ReservationTransactions::class)->run(fn () => DB::table('service_categories')->insert(['name' => 'Child', 'slug' => 'child-success', 'is_active' => 1]));
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseMissing('service_categories', ['slug' => 'parent-owned']);
        $this->assertDatabaseMissing('service_categories', ['slug' => 'child-success']);
        $this->assertHoldIntegrity();
    }
}
