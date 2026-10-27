<?php

namespace Tests\Feature;

use App\Models\Emotion;
use App\Models\Profesionista;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    private function useSeparateMemoryDatabase(): void
    {
        config(['database.connections.migration_probe' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('migration_probe');
    }

    public function test_all_migrations_run_on_an_empty_memory_database_and_can_be_checked_again(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $migrationCount = count(glob(database_path('migrations/*.php')));
        $this->assertDatabaseCount('migrations', $migrationCount);
        $this->assertTrue(Schema::hasColumns('profesionistas', [
            'google_access_token', 'google_refresh_token', 'google_calendar_id', 'google_token_expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('citas', [
            'start_datetime', 'end_datetime', 'starts_at', 'ends_at', 'estado', 'status', 'google_event_id',
        ]));
        $this->assertDatabaseCount('citas', 0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertDatabaseCount('migrations', $migrationCount);
    }

    public function test_google_migration_preserves_existing_credentials_in_both_directions(): void
    {
        $professional = Profesionista::create([
            'nombre' => 'Prueba', 'email' => 'professional@example.test',
            'password' => Hash::make('test-password'), 'cedula' => 'TEST-001',
            'google_access_token' => 'fake-existing-access', 'google_refresh_token' => 'fake-existing-refresh',
            'google_calendar_id' => 'existing-calendar', 'google_token_expires_at' => '2026-10-22 12:00:00',
        ]);
        $professional->refresh();
        $before = $professional->getAttributes();
        $migration = require database_path('migrations/2026_02_20_224556_add_google_fields_to_profesionistas_table.php');

        $migration->up();
        $migration->down();

        $this->assertSame($before, $professional->fresh()->getAttributes());
    }

    public function test_google_migration_adds_only_missing_columns_to_a_partial_legacy_schema(): void
    {
        $this->useSeparateMemoryDatabase();
        Schema::create('profesionistas', function (Blueprint $table) {
            $table->id();
            $table->text('google_access_token')->nullable();
        });
        DB::table('profesionistas')->insert(['id' => 1, 'google_access_token' => 'fake-existing-access']);
        $migration = require database_path('migrations/2026_02_20_224556_add_google_fields_to_profesionistas_table.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('profesionistas', [
            'google_access_token', 'google_refresh_token', 'google_calendar_id', 'google_token_expires_at',
        ]));
        $this->assertSame('fake-existing-access', DB::table('profesionistas')->value('google_access_token'));
    }

    public function test_citas_migration_backfills_new_columns_without_changing_legacy_rows_or_existing_aliases(): void
    {
        $this->useSeparateMemoryDatabase();
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2025_08_13_022507_create_profesionistas_table.php',
            '2026_02_16_031436_create_citas_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $user = User::factory()->create();
        $professional = Profesionista::create([
            'nombre' => 'Prueba', 'email' => 'professional@example.test',
            'password' => Hash::make('test-password'), 'cedula' => 'TEST-001',
        ]);
        $states = ['pendiente' => 'pending', 'aceptada' => 'scheduled', 'rechazada' => 'rejected', 'cancelada' => 'cancelled'];
        foreach (array_keys($states) as $index => $estado) {
            DB::table('citas')->insert([
                'user_id' => $user->id, 'profesionista_id' => $professional->id,
                'start_datetime' => '2026-10-'.(23 + $index).' 10:00:00',
                'end_datetime' => '2026-10-'.(23 + $index).' 10:50:00',
                'estado' => $estado, 'motivo' => 'Conservar este motivo',
            ]);
        }
        $before = DB::table('citas')->orderBy('id')->get()->map(fn ($row) => (array) $row);
        $migration = require database_path('migrations/2026_02_21_071030_add_start_end_to_citas_table.php');
        $migration->up();

        foreach (DB::table('citas')->orderBy('id')->get() as $index => $row) {
            $this->assertSame($row->start_datetime, $row->starts_at);
            $this->assertSame($row->end_datetime, $row->ends_at);
            $this->assertSame($states[$row->estado], $row->status);
            foreach ($before[$index] as $column => $value) {
                $this->assertSame($value, $row->{$column});
            }
        }

        DB::table('citas')->where('id', 1)->update(['status' => 'legacy-status', 'starts_at' => '2030-01-01 10:00:00']);
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('citas', ['id' => 1, 'status' => 'legacy-status', 'starts_at' => '2030-01-01 10:00:00']);
        $this->assertDatabaseCount('citas', 4);
    }

    public function test_factories_and_repeatable_seeders_match_the_schema_without_overwriting_data(): void
    {
        $existingUser = User::factory()->create(['email' => 'test@example.com', 'name' => 'Nombre conservado'])->fresh();
        $before = $existingUser->getAttributes();
        Emotion::create(['name' => 'Contenido conservado', 'slug' => 'calma', 'image_path' => 'existing.png']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $existingUser->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('emotions', 5);
        $this->assertDatabaseHas('emotions', ['slug' => 'calma', 'name' => 'Contenido conservado', 'image_path' => 'existing.png']);
    }

    public function test_the_development_seeder_can_run_on_a_new_installation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'gender' => 'Prefiero no decirlo']);
        $this->assertDatabaseCount('emotions', 5);
    }
}
