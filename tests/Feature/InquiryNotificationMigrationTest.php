<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\InquiryNotification;
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

class InquiryNotificationMigrationTest extends TestCase
{
    use InquiryFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
            'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_from_29_migrations_and_notification_only_reversal_preserve_existing_data_without_backfill(): void
    {
        config(['database.connections.notice_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('notice_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_29_000000'));
        $this->assertCount(29, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'notice_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 29);
        $this->assertFalse(Schema::hasTable('inquiry_notifications'));
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $id = DB::table('service_inquiries')->insertGetId(['service_id' => $service->id, 'client_user_id' => $client->id,
            'service_title' => $service->title, 'message' => 'Solicitud pendiente anterior a las notificaciones.', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]);
        $legacy = Profesionista::create(['nombre' => 'Identidad anterior', 'email' => $provider->email, 'cedula' => 'NOTICE-UPGRADE',
            'password' => Hash::make('separate-password'), 'google_access_token' => 'fake-preserved-access',
            'google_refresh_token' => 'fake-preserved-refresh', 'remember_token' => 'fake-preserved-remember']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id,
            'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true]);
        DB::table('sessions')->insert(['id' => 'notice-old-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);
        // Conservar el escenario 29→30 y sus aserciones; 5A se verifica por separado.
        $noticeFiles = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_30_000000'));
        $noticeOptions = ['--database' => 'notice_upgrade', '--path' => $noticeFiles, '--realpath' => true, '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $noticeOptions));
        $this->assertDatabaseCount('migrations', 30);
        $this->assertDatabaseCount('inquiry_notifications', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $inquiry = ServiceInquiry::findOrFail($id);
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Accept);
        $this->assertDatabaseHas('inquiry_notifications', ['user_id' => $client->id, 'service_inquiry_id' => $id, 'event' => 'accepted']);
        $this->assertSame($client->id, $inquiry->client->id);
        $this->assertSame($provider->id, $inquiry->service->providerProfile->user->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $afterAction = $this->snapshot($tables);
        // Reversión exclusivamente de la migración nueva, solo en SQLite en memoria.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'notice_upgrade', '--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('inquiry_notifications'));
        $this->assertDatabaseCount('migrations', 29);
        $this->assertSame($afterAction, $this->snapshot($tables));
        $this->assertSame(0, Artisan::call('migrate', $noticeOptions));
        $this->assertDatabaseCount('migrations', 30);
        $this->assertDatabaseCount('inquiry_notifications', 0);
        $this->assertSame($afterAction, $this->snapshot($tables));
    }

    public function test_database_rejects_nonexistent_user_or_inquiry_and_unknown_events(): void
    {
        $inquiry = $this->inquiry();
        $notice = InquiryNotification::sole();
        $base = collect($notice->getAttributes())->except('id')->all();
        foreach ([['user_id' => 999999], ['service_inquiry_id' => 999999], ['event' => 'admin-granted']] as $invalid) {
            try {
                DB::table('inquiry_notifications')->insert(array_replace($base, $invalid));
                $this->fail('La base de datos debe rechazar la referencia o evento inválido.');
            } catch (QueryException $exception) {
                $this->assertDatabaseCount('inquiry_notifications', 1);
            }
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame($inquiry->id, $notice->fresh()->inquiry->id);
    }

    public function test_notification_history_prevents_accidental_physical_deletion_of_its_inquiry(): void
    {
        $inquiry = $this->inquiry();
        $this->expectException(QueryException::class);
        $inquiry->delete();
    }
}
