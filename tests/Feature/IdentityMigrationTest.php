<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleMemberships;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IdentityMigrationTest extends TestCase
{
    private const NEW_MIGRATIONS = [
        '2026_10_23_000000_create_roles_and_role_user_tables.php',
        '2026_10_23_000001_create_provider_profiles_table.php',
    ];

    private const LEGACY_TABLES = [
        'users', 'profesionistas', 'citas', 'profesionista_disponibilidades',
        'profesionista_bloqueos', 'posts', 'comments', 'likes', 'reminders', 'sessions',
    ];

    private function legacyDatabase(): array
    {
        config(['database.connections.legacy_upgrade' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('legacy_upgrade');
        $files = array_filter(glob(database_path('migrations/*.php')), fn ($file) =>
            ! in_array(basename($file), self::NEW_MIGRATIONS, true));
        $this->assertCount(24, $files);
        foreach ($files as $file) {
            (require $file)->up();
        }

        $user = User::factory()->create(['email' => 'same@example.test']);
        $professional = Profesionista::create([
            'nombre' => 'Identidad antigua', 'email' => $user->email, 'cedula' => 'LEGACY-001',
            'password' => Hash::make('different-legacy-password'),
            'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh',
        ]);
        $cita = Cita::create([
            'user_id' => $user->id, 'profesionista_id' => $professional->id,
            'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar',
        ]);
        ProfesionistaDisponibilidad::create([
            'profesionista_id' => $professional->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true,
        ]);
        DB::table('profesionista_bloqueos')->insert([
            'profesionista_id' => $professional->id,
            'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00',
        ]);
        $post = Post::create(['user_id' => $user->id, 'content' => 'Publicación anterior']);
        Comment::create(['user_id' => $user->id, 'post_id' => $post->id, 'content' => 'Comentario anterior']);
        $post->likes()->create(['user_id' => $user->id, 'post_id' => $post->id]);
        DB::table('reminders')->insert(['user_id' => $user->id, 'title' => 'Anterior', 'remind_at' => '2030-01-07 09:00:00']);
        DB::table('sessions')->insert(['id' => 'legacy-session', 'user_id' => $user->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);

        return [$user, $professional, $cita];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::LEGACY_TABLES as $table) {
            $snapshot[$table] = [
                'columns' => Schema::getColumnListing($table),
                'rows' => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ];
        }

        return $snapshot;
    }

    public function test_upgrade_and_reversal_preserve_all_legacy_rows_columns_and_relationships(): void
    {
        [$user, $professional, $cita] = $this->legacyDatabase();
        $before = $this->snapshot();

        foreach (self::NEW_MIGRATIONS as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertSame($user->id, $cita->fresh()->user->id);
        $this->assertSame($professional->id, $cita->fresh()->profesionista->id);
        $this->assertSame($professional->id, ProfesionistaDisponibilidad::firstOrFail()->profesionista->id);
        $this->assertCount(1, $user->posts);
        $this->assertCount(1, $user->comments);
        $this->assertCount(1, $user->likes);
        $this->assertCount(1, $user->reminders);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        app(RoleMemberships::class)->enroll($user, RoleName::Provider);
        $user->providerProfile()->create(['display_name' => 'Perfil central explícito']);
        $this->assertSame($before, $this->snapshot());

        foreach (array_reverse(self::NEW_MIGRATIONS) as $file) {
            (require database_path('migrations/'.$file))->down();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertFalse(Schema::hasTable('role_user'));
        $this->assertFalse(Schema::hasTable('provider_profiles'));

        foreach (self::NEW_MIGRATIONS as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->assertDatabaseCount('role_user', 0);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_role_membership_primary_key_prevents_duplicate_assignments(): void
    {
        $user = User::factory()->create();
        $roleId = Role::where('slug', 'provider')->firstOrFail()->id;
        $user->roles()->attach($roleId);

        $this->expectException(QueryException::class);
        $user->roles()->attach($roleId);
    }

    public function test_database_enforces_one_profile_per_user(): void
    {
        $user = User::factory()->create();
        $user->providerProfile()->create(['display_name' => 'Primero']);

        $this->expectException(QueryException::class);
        $user->providerProfile()->create(['display_name' => 'Duplicado']);
    }

    public function test_profiles_cannot_reference_nonexistent_users(): void
    {
        $this->expectException(QueryException::class);
        DB::table('provider_profiles')->insert(['user_id' => 99999, 'display_name' => 'Sin identidad']);
    }

    public function test_profile_mass_assignment_does_not_accept_owner_or_permission_fields(): void
    {
        $profile = new ProviderProfile([
            'display_name' => 'Perfil', 'user_id' => 99999, 'roles' => ['admin'], 'is_admin' => true,
        ]);
        $this->assertSame(['display_name' => 'Perfil'], $profile->getAttributes());
    }
}
