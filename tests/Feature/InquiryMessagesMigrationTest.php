<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Profesionista;
use App\Models\ServiceInquiryMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MessageFixtures;
use Tests\TestCase;

class InquiryMessagesMigrationTest extends TestCase
{
    use MessageFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table),
            'rows' => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_30_to_31_and_message_only_reversal_preserve_all_previous_rows_and_notifications(): void
    {
        config(['database.connections.message_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('message_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_10_30_000000'));
        $this->assertCount(30, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'message_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 30);
        $this->assertFalse(Schema::hasTable('service_inquiry_messages'));
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $client->email, 'cedula' => 'MESSAGE-UPGRADE',
            'password' => Hash::make('different-old-password'), 'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00',
            'end_datetime' => '2030-01-07 10:50:00', 'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        DB::table('sessions')->insert(['id' => 'message-existing-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => $table !== 'migrations'));
        $before = $this->snapshot($tables);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'message_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 31);
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $message = $this->message($client, $inquiry);
        $this->assertSame($client->id, $message->author->id);
        $this->assertSame($inquiry->id, $message->inquiry->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        // Solo esta conexión en memoria: down() elimina exclusivamente el historial de mensajes nuevo.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'message_upgrade', '--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('service_inquiry_messages'));
        $this->assertDatabaseCount('migrations', 30);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'message_upgrade', '--force' => true]));
        $this->assertDatabaseCount('migrations', 31);
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_database_enforces_both_central_foreign_keys_and_explicit_indexes(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $message = $this->message($client, $inquiry);
        $base = collect($message->getAttributes())->except('id')->all();
        foreach ([['author_user_id' => 999999], ['service_inquiry_id' => 999999]] as $invalid) {
            try {
                DB::table('service_inquiry_messages')->insert(array_replace($base, $invalid));
                $this->fail('No se permite un autor o solicitud inexistente.');
            } catch (QueryException $exception) {
                $this->assertDatabaseCount('service_inquiry_messages', 1);
            }
        }
        $keys = DB::select('PRAGMA foreign_key_list(service_inquiry_messages)');
        $this->assertEqualsCanonicalizing(['users', 'service_inquiries'], array_column($keys, 'table'));
        foreach ($keys as $key) $this->assertSame('RESTRICT', $key->on_delete);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $indexes = DB::select('PRAGMA index_list(service_inquiry_messages)');
        $unique = collect($indexes)->firstWhere('name', 'inquiry_messages_one_submission');
        $this->assertSame(1, $unique->unique);
        $this->assertContains('inquiry_messages_conversation_order', array_column($indexes, 'name'));
        $this->assertSame($message->body, ServiceInquiryMessage::sole()->body);
    }
}
