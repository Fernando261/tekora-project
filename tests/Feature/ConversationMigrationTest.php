<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\InquiryReadProgress;
use App\Models\Profesionista;
use App\Services\InquiryReadTracking;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReadFixtures;
use Tests\TestCase;

class ConversationMigrationTest extends TestCase
{
    use ReadFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
            'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_31_to_32_and_read_only_reversal_preserve_all_old_messages_idempotency_and_notifications(): void
    {
        config(['database.connections.read_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('read_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_30_000001'));
        $this->assertCount(31, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'read_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 31); $this->assertFalse(Schema::hasTable('service_inquiry_reads'));
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $this->message($client, $inquiry); $received = $this->message($provider, $inquiry);
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => $client->email, 'cedula' => 'READ-UPGRADE', 'password' => Hash::make('separate-old-password'),
            'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00',
            'end_datetime' => '2030-01-07 10:50:00', 'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        DB::table('sessions')->insert(['id' => 'read-existing-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'read_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 32); $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame(1, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $progress = app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $this->assertSame($received->id, $progress->lastReadMessage->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame($before, $this->snapshot($tables)); $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        // Solo memoria: down() elimina progreso e índices nuevos, conservando el esquema y datos de 5A.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'read_upgrade', '--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('service_inquiry_reads')); $this->assertDatabaseCount('migrations', 31);
        $this->assertSame($before, $this->snapshot($tables));
        $indexes = array_column(DB::select('PRAGMA index_list(service_inquiry_messages)'), 'name');
        $this->assertContains('inquiry_messages_one_submission', $indexes); $this->assertContains('inquiry_messages_conversation_order', $indexes);
        $this->assertNotContains('inquiry_messages_identity', $indexes); $this->assertNotContains('inquiry_messages_received_order', $indexes);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'read_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 32); $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_database_rejects_duplicate_progress_nonexistent_users_and_cross_inquiry_message_markers(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry);
        $other = $this->inquiry($client, $provider); $otherMessage = $this->message($provider, $other);
        $progress = app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $base = collect($progress->getAttributes())->except('id')->all();
        foreach ([[], ['user_id' => 999999], ['service_inquiry_id' => 999999], ['service_inquiry_id' => $other->id],
            ['user_id' => $provider->id, 'last_read_message_id' => $otherMessage->id], ['last_read_message_id' => 999999]] as $invalid) {
            try {
                DB::table('service_inquiry_reads')->insert(array_replace($base, $invalid));
                $this->fail('La base debe rechazar duplicados y referencias ajenas o inexistentes.');
            } catch (QueryException $exception) {
                $this->assertDatabaseCount('service_inquiry_reads', 1);
            }
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame($client->id, InquiryReadProgress::sole()->user_id);
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new InquiryReadProgress(['user_id' => 999999, 'last_read_message_id' => 999999]);
    }
}
