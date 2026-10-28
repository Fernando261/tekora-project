<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Models\ServiceCategory;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class CatalogMigrationTest extends TestCase
{
    use CatalogFixtures;

    public function test_category_seed_is_repeatable_and_preserves_existing_customization_and_inactive_state(): void
    {
        $custom = $this->category('custom');
        $existing = $this->category();
        $existing->update(['name' => 'Nombre conservado', 'description' => 'Texto conservado', 'is_active' => false, 'sort_order' => 999]);
        $before = $existing->fresh()->getAttributes();
        $this->seed(ServiceCategorySeeder::class);
        $this->seed(ServiceCategorySeeder::class);
        $this->assertDatabaseCount('service_categories', 6);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseHas('service_categories', ['id' => $custom->id, 'slug' => 'custom']);
        $this->assertSame(['reparacion-celulares', 'computadoras', 'desarrollo-web', 'software-servicios-digitales'],
            ServiceCategory::active()->where('slug', '!=', 'custom')->ordered()->pluck('slug')->all());
    }

    public function test_category_slugs_are_unique_in_the_database(): void
    {
        $this->category();
        $this->expectException(QueryException::class);
        ServiceCategory::create(['slug' => 'soporte-tecnico', 'name' => 'Duplicado']);
    }

    public function test_services_cannot_reference_a_nonexistent_provider_profile(): void
    {
        $category = $this->category();
        $this->expectException(QueryException::class);
        DB::table('services')->insert(['provider_profile_id' => 99999, 'service_category_id' => $category->id,
            'title' => 'Inválido', 'description' => 'Sin propietario', 'modality' => 'remote', 'status' => 'draft']);
    }

    public function test_categories_with_services_cannot_be_deleted(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->expectException(QueryException::class);
        $service->category->delete();
    }

    public function test_profiles_with_services_cannot_be_deleted(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->expectException(QueryException::class);
        $service->providerProfile->delete();
    }

    public function test_database_rejects_unknown_service_states(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->expectException(QueryException::class);
        DB::table('services')->where('id', $service->id)->update(['status' => 'verified']);
    }

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
                'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];
        }

        return $snapshot;
    }

    public function test_upgrade_from_26_applied_migrations_and_catalog_only_reversal_preserve_existing_data(): void
    {
        config(['database.connections.catalog_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('catalog_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_28_000000'));
        $this->assertCount(26, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'catalog_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 26);
        $this->assertFalse(Schema::hasTable('services'));

        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $profile = $owner->providerProfile()->create(['display_name' => 'Perfil conservado', 'bio' => 'Biografía anterior']);
        $legacy = Profesionista::create(['nombre' => 'Identidad anterior', 'email' => $owner->email,
            'password' => Hash::make('different-old-password'), 'cedula' => 'UPGRADE-3A',
            'google_access_token' => 'fake-access-preserved', 'google_refresh_token' => 'fake-refresh-preserved']);
        $cita = Cita::create(['user_id' => $owner->id, 'profesionista_id' => $legacy->id,
            'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id,
            'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        $post = Post::create(['user_id' => $owner->id, 'content' => 'Publicación anterior']);
        Comment::create(['user_id' => $owner->id, 'post_id' => $post->id, 'content' => 'Comentario anterior']);
        $post->likes()->create(['user_id' => $owner->id, 'post_id' => $post->id]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $owner->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);

        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'catalog_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 28);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertDatabaseCount('service_categories', 0);
        $this->assertDatabaseCount('services', 0);
        $this->seed(ServiceCategorySeeder::class);
        $this->service($owner);
        $this->assertSame($profile->id, $owner->providerProfile()->firstOrFail()->id);
        $this->assertSame($owner->id, $cita->fresh()->user->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame($before, $this->snapshot($tables));

        // Solo memoria: comprobar down() de las dos migraciones del catálogo, nunca una base real.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'catalog_upgrade', '--step' => 2, '--force' => true]));
        $this->assertFalse(Schema::hasTable('services'));
        $this->assertFalse(Schema::hasTable('service_categories'));
        $this->assertDatabaseCount('migrations', 26);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'catalog_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 28);
        $this->assertSame($before, $this->snapshot($tables));
    }
}
