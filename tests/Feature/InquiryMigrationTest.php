<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Models\ServiceInquiry;
use App\Services\InquiryWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryMigrationTest extends TestCase
{
    use InquiryFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
                'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];
        }

        return $snapshot;
    }

    public function test_upgrade_from_28_applied_migrations_preserves_all_previous_data_and_only_reverses_the_new_table(): void
    {
        config(['database.connections.inquiry_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('inquiry_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_28_000002'));
        $this->assertCount(28, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'inquiry_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 28);
        $this->assertFalse(Schema::hasTable('service_inquiries'));
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($provider);
        $legacy = Profesionista::create(['nombre' => 'Profesional antiguo', 'email' => $provider->email, 'cedula' => 'UPGRADE-3B',
            'password' => Hash::make('separate-old-password'), 'google_access_token' => 'fake-preserved-access',
            'google_refresh_token' => 'fake-preserved-refresh', 'google_calendar_id' => 'fake-preserved-calendar']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id,
            'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Cita conservada']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $post = Post::create(['user_id' => $client->id, 'content' => 'Publicación anterior']);
        Comment::create(['user_id' => $client->id, 'post_id' => $post->id, 'content' => 'Comentario anterior']);
        $post->likes()->create(['user_id' => $client->id, 'post_id' => $post->id]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);

        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'inquiry_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 29);
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Nueva necesidad de soporte para el equipo.')['inquiry'];
        $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame($client->id, $inquiry->fresh()->client->id);
        $this->assertSame($provider->id, $inquiry->fresh()->service->providerProfile->user->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertDatabaseCount('citas', 1);

        // Solo SQLite en memoria: la reversión elimina exclusivamente los datos nuevos de solicitudes.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'inquiry_upgrade', '--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('service_inquiries'));
        $this->assertDatabaseCount('migrations', 28);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'inquiry_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 29);
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_inquiries_cannot_reference_a_nonexistent_client(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->expectException(QueryException::class);
        DB::table('service_inquiries')->insert(['service_id' => $service->id, 'client_user_id' => 99999,
            'service_title' => $service->title, 'message' => 'Sin cliente real', 'status' => 'pending']);
    }

    public function test_inquiries_cannot_reference_a_nonexistent_service(): void
    {
        $client = $this->account(RoleName::Client);
        $this->expectException(QueryException::class);
        DB::table('service_inquiries')->insert(['service_id' => 99999, 'client_user_id' => $client->id,
            'service_title' => 'Sin servicio real', 'message' => 'No debería guardarse', 'status' => 'pending']);
    }

    public function test_a_service_with_inquiry_history_cannot_be_physically_deleted(): void
    {
        $inquiry = $this->inquiry();
        $this->expectException(QueryException::class);
        $inquiry->service->delete();
    }

    public function test_a_client_with_inquiry_history_cannot_be_physically_deleted(): void
    {
        $inquiry = $this->inquiry();
        $this->expectException(QueryException::class);
        $inquiry->client->delete();
    }

    public function test_database_status_constraint_rejects_unknown_states(): void
    {
        $inquiry = $this->inquiry();
        $this->expectException(QueryException::class);
        DB::table('service_inquiries')->where('id', $inquiry->id)->update(['status' => 'paid']);
    }
}
