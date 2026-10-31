<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Models\ProviderAvailabilitySetting;
use App\Services\InquiryReadTracking;
use App\Services\ProviderAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityMigrationTest extends TestCase
{
    use AvailabilityFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
            'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_32_to_33_and_reversal_preserve_every_existing_table_and_legacy_identity(): void
    {
        config(['database.connections.availability_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('availability_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_30_000002'));
        $this->assertCount(32, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'availability_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 32);
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry, 'Mensaje anterior privado.');
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $legacy = Profesionista::create(['nombre' => 'Profesional antiguo', 'email' => $provider->email, 'cedula' => 'AVAILABILITY-UPGRADE',
            'password' => Hash::make('old-separate-password'), 'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00',
            'end_datetime' => '2030-01-07 10:50:00', 'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00', 'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        DB::table('sessions')->insert(['id' => 'availability-old-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'availability_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 33); $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $setting = app(ProviderAvailability::class)->save($provider, $this->availabilityData());
        $this->assertSame($provider->providerProfile->id, $setting->providerProfile->id);
        $this->assertSame($provider->providerProfile->id, $provider->providerProfile->weeklyIntervals()->firstOrFail()->providerProfile->id);
        $this->assertSame($provider->providerProfile->id, $provider->providerProfile->blockedDates()->firstOrFail()->providerProfile->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame($before, $this->snapshot($tables)); $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        // Solo memoria: revertir exclusivamente las tablas nuevas de 6A, nunca datos persistentes.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'availability_upgrade', '--step' => 1, '--force' => true]));
        foreach (['provider_availability_settings', 'provider_weekly_intervals', 'provider_blocked_dates'] as $table) $this->assertFalse(Schema::hasTable($table));
        $this->assertDatabaseCount('migrations', 32); $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'provider_weekly_%'"));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'availability_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 33); $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_database_enforces_ranges_dates_uniqueness_and_central_foreign_keys(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner); $profile = $owner->providerProfile;
        app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        $base = ['provider_profile_id' => $profile->id, 'day_of_week' => 4, 'start_minute' => 540, 'end_minute' => 720];
        foreach ([['day_of_week' => 0], ['day_of_week' => 8], ['day_of_week' => 1.5], ['start_minute' => -1], ['end_minute' => 1440],
            ['start_minute' => 800], ['start_minute' => 540.5], ['provider_profile_id' => 999999]] as $invalid) {
            try { DB::table('provider_weekly_intervals')->insert(array_replace($base, $invalid)); $this->fail('La base debe rechazar rangos o referencias inválidos.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount('provider_weekly_intervals', 3); }
        }
        foreach (['2030-01-01', '2027-02-29', '2100-02-29', '2026-04-31', 'invalid', '1999-12-31'] as $date) {
            try { DB::table('provider_blocked_dates')->insert(['provider_profile_id' => $profile->id, 'blocked_date' => $date]); $this->fail('La fecha inválida o duplicada no debe persistirse.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount('provider_blocked_dates', 2); }
        }
        foreach (['provider_availability_settings', 'provider_weekly_intervals', 'provider_blocked_dates'] as $table) {
            $keys = DB::select('PRAGMA foreign_key_list('.$table.')');
            $this->assertSame(['provider_profiles'], array_column($keys, 'table'));
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new ProviderAvailabilitySetting(['provider_profile_id' => 999999, 'timezone' => 'UTC', 'revision' => 1]);
    }
}
