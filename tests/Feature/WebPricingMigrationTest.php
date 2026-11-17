<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Payments\ServicePricing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class WebPricingMigrationTest extends TestCase
{
    use CatalogFixtures;

    public function test_upgrade_and_rollback_preserve_all_historical_schema_and_rows(): void
    {
        config(['database.connections.pricing_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('pricing_upgrade');
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/pricing-historical-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        $historical = array_map(fn ($file) => base_path($file), array_keys($manifest));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pricing_upgrade', '--path' => $historical, '--realpath' => true, '--force' => true]));
        $service = $this->service($this->account(RoleName::Provider));
        $tables = array_column(Schema::getTables(), 'name');
        $snapshot = function () use ($tables) {
            $result = [];
            foreach ($tables as $table) {
                if ($table !== 'migrations') {
                    $result[$table] = ['rows' => DB::table($table)->get()->toArray(), 'columns' => Schema::getColumns($table), 'indexes' => Schema::getIndexes($table)];
                }
            }

            return $result;
        };
        $before = $snapshot();
        $triggers = DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' ORDER BY name");
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pricing_upgrade', '--path' => database_path('migrations/2026_11_18_000000_create_web_pricing_and_quotes.php'), '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 38);
        $this->assertEquals($before, $snapshot());
        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'pricing_upgrade', '--step' => 1, '--force' => true]));
        $this->assertDatabaseCount('migrations', 37);
        $this->assertEquals($before, $snapshot());
        $this->assertEquals($triggers, DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' ORDER BY name"));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
    }

    public static function invalidStoredPrice(): array
    {
        return [['total_cents', 0], ['total_cents', 1.1], ['total_cents', 1000000001], ['commission_cents', 15001], ['provider_cents', 85001], ['currency', 'USD'], ['duration_minutes', 30], ['policy_version', 'unapproved'], ['commission_basis_points', 2000], ['service_id', 99999], ['provider_profile_id', 99999], ['created_by', 99999], ['version', 7], ['idempotency_key', 'bad-key'], ['created_at_utc', 'not-a-date']];
    }

    #[DataProvider('invalidStoredPrice')]
    public function test_database_boundary_rejects_invalid_money_identity_sequence_and_keys(string $field, mixed $value): void
    {
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        app(ServicePricing::class)->publish($provider, $service->id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $row = (array) DB::table('service_price_versions')->first();
        $row = array_replace($row, ['id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'version' => 2], [$field => $value]);
        try {
            DB::table('service_price_versions')->insert($row);
            $this->fail('SQLite debía rechazar el registro.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertDatabaseCount('service_price_versions', 1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
    }
}
