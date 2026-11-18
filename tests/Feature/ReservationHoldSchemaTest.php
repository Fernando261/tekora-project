<?php

namespace Tests\Feature;

use App\Models\ServiceReservation;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HistoricalReservationFixtures;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ReservationHoldSchemaTest extends TestCase
{
    use HistoricalReservationFixtures, SlotHoldFixtures;

    private function originalSchema(): array
    {
        config(['database.connections.hold_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('hold_upgrade');
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/slot-hold-historical-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'hold_upgrade', '--path' => array_map(fn ($p) => base_path($p), array_keys($manifest)), '--realpath' => true, '--force' => true]));

        return $manifest;
    }

    public function test_all_38_original_migrations_remain_byte_identical(): void
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/slot-hold-historical-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(38, $manifest);
        foreach ($manifest as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), $path);
        }
        $this->assertCount(39, glob(database_path('migrations/*.php')));
    }

    public function test_additive_upgrade_preserves_historical_confirmed_rows_and_empty_reversal_restores_constraints(): void
    {
        $this->originalSchema();
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->historicalProposal($inquiry);
        DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id, 'last_reason' => 'confirm']);
        $tables = array_column(Schema::getTables(), 'name');
        $snapshot = function () use ($tables) {
            $rows = [];
            foreach ($tables as $t) {
                if ($t !== 'migrations') {
                    $rows[$t] = DB::table($t)->get()->map(function ($row) use ($t) {
                        $v = (array) $row;
                        if ($t === 'service_reservations') {
                            unset($v['quote_id']);
                        }

return $v;
                    })->all();
                }
            }

            return $rows;
        };
        $before = $snapshot();
        $columns = Schema::getColumnListing('service_reservations');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'hold_upgrade', '--path' => database_path('migrations/2026_11_19_000000_add_reservation_slot_holds.php'), '--realpath' => true, '--force' => true]));
        $this->assertSame($before, $snapshot());
        $this->assertSame([...$columns, 'quote_id'], Schema::getColumnListing('service_reservations'));
        $this->assertNull(ServiceReservation::findOrFail($reservation->id)->quote_id);
        $this->assertSame(1, DB::table('reservation_occupancy')->count());
        $this->assertDatabaseCount('reservation_holds', 0);
        $this->assertHoldIntegrity();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'hold_upgrade', '--step' => 1, '--force' => true]));
        $this->assertSame($before, $snapshot());
        $this->assertSame($columns, Schema::getColumnListing('service_reservations'));
        $this->assertDatabaseCount('migrations', 38);
        $this->assertFalse(Schema::hasTable('reservation_holds'));
        $this->assertTrue(collect(DB::select("SELECT name FROM sqlite_master WHERE type='index'"))->contains('name', 'reservations_one_active_inquiry'));
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_reversal_refuses_to_destroy_even_expired_commercial_history(): void
    {
        [, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $this->travel(900)->seconds();
        app(ReservationWorkflow::class)->refreshExpirations();
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_action_receipts']);
        $schema = DB::select('SELECT type,name,sql FROM sqlite_master ORDER BY type,name');
        try {
            (require database_path('migrations/2026_11_19_000000_add_reservation_slot_holds.php'))->down();
            $this->fail('No borrar historial.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('historial', $error->getMessage());
        }
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_action_receipts']));
        $this->assertEquals($schema, DB::select('SELECT type,name,sql FROM sqlite_master ORDER BY type,name'));
        $this->assertHoldIntegrity();
    }

    public function test_sql_cannot_fake_future_completion_to_free_a_confirmed_slot(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $this->holdInput());
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events']);
        try {
            DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'completed', 'version' => 3, 'last_reason' => 'complete', 'last_actor_user_id' => $provider->id, 'updated_at' => $reservation->ends_at_utc]);
            $this->fail('No completar antes de tiempo por SQL directo.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events']));
        $this->assertSame(1, DB::table('reservation_occupancy')->count());
        $this->assertHoldIntegrity();
    }
}
