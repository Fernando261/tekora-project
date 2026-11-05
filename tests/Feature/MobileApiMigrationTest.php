<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class MobileApiMigrationTest extends TestCase
{
    use CatalogFixtures;

    public function test_upgrade_36_to_37_and_rollback_preserve_every_previous_table_in_memory(): void
    {
        config(['database.connections.mobile_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('mobile_upgrade');
        $historical = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($file) => basename($file) < '2026_11_04_000000'));
        $this->assertCount(36, $historical);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'mobile_upgrade', '--path' => $historical,
            '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 36);
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $this->service($owner);
        DB::table('sessions')->insert(['id' => 'preserved-web-session', 'user_id' => $owner->id,
            'payload' => 'opaque-session', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $snapshot = function () use ($tables) {
            $result = [];
            foreach ($tables as $table) {
                $result[$table] = ['columns' => Schema::getColumns($table), 'indexes' => Schema::getIndexes($table),
                    'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()];
            }
            $result['triggers'] = DB::select("SELECT name, sql FROM sqlite_master WHERE type='trigger' ORDER BY name");

            return $result;
        };
        $before = $snapshot();
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'mobile_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 37);
        $this->assertEquals($before, $snapshot());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'mobile_upgrade', '--step' => 1, '--force' => true]));
        $this->assertDatabaseCount('migrations', 36);
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertEquals($before, $snapshot());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
    }
}
