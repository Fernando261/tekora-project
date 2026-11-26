<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

#[Group('sqlite-persistent-fixture-w62e')]
class PersistentSqliteFixtureTest extends TestCase
{
    use ReservationFixtures;

    public function test_export_only_fresh_synthetic_migration_fixture(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertFalse(is_file(base_path('.env')));
        [$client, $provider, $inquiry] = $this->reservationContext('UTC');
        $this->seedRetired($client);
        $this->seedProtectedInfrastructure($client);
        $this->message($client, $inquiry, 'Synthetic persistent lab');
        $this->proposeReservation($inquiry);
        DB::statement("INSERT INTO main.cache(key,value,expiration) VALUES ('synthetic-blob',x'0031FF',123)");
        $pdo = DB::connection()->getPdo();
        $objects = $pdo->query("SELECT type,name,tbl_name,sql FROM main.sqlite_master WHERE substr(name,1,7)<>'sqlite_' COLLATE NOCASE ORDER BY type,name")->fetchAll(\PDO::FETCH_ASSOC);
        $tables = [];
        foreach ($objects as $o) {
            if ($o['type'] !== 'table') {
                continue;
            }
            $q = '"'.str_replace('"', '""', $o['name']).'"';
            $tables[$o['name']] = ['sql' => $o['sql'], 'parents' => array_column($pdo->query('PRAGMA main.foreign_key_list('.$q.')')->fetchAll(\PDO::FETCH_ASSOC), 'table'), 'rows' => $pdo->query('SELECT * FROM main.'.$q)->fetchAll(\PDO::FETCH_ASSOC)];
        }
        // Encode BLOBs explicitly; JSON never carries invalid UTF-8 bytes.
        foreach ($tables['cache']['rows'] as &$row) {
            if ($row['key'] === 'synthetic-blob') {
                $row['value'] = ['blob' => 'ADH/'];
            }
        }
        unset($row);
        $this->assertCount(35, $tables);
        $this->assertDatabaseCount('migrations', 37);
        if (getenv('TEKORA_W62E_FIXTURE') === '1') {
            echo 'W62E_FIXTURE='.base64_encode(json_encode(['tables' => $tables, 'objects' => $objects, 'sequences' => $pdo->query('SELECT name,seq FROM main.sqlite_sequence')->fetchAll(\PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR))."\n";
        }
    }

    private function seedRetired(User $user, string $media = 'avatars/shared.png'): void
    {
        DB::table('main.profesionistas')->insert(['id' => 1, 'nombre' => 'Synthetic', 'email' => $user->email,
            'password' => 'synthetic-secret', 'cedula' => 'SYNTHETIC', 'google_access_token' => 'synthetic-token']);
        DB::table('main.posts')->insert(['id' => 1, 'user_id' => $user->id, 'media_path' => $media, 'deleted_at' => now()]);
        DB::table('main.comments')->insert(['id' => 1, 'user_id' => $user->id, 'post_id' => 1, 'content' => 'Synthetic', 'media_path' => $media]);
        DB::table('main.likes')->insert(['user_id' => $user->id, 'post_id' => 1, 'likeable_id' => 1, 'likeable_type' => 'App\\Models\\Post']);
        DB::table('main.reminders')->insert(['user_id' => $user->id, 'title' => 'Synthetic', 'remind_at' => '2030-01-01 10:00:00']);
        DB::table('main.emotions')->insert(['name' => 'Synthetic', 'slug' => 'synthetic', 'image_path' => $media]);
        DB::table('main.citas')->insert(['user_id' => $user->id, 'profesionista_id' => 1,
            'start_datetime' => '2030-01-01 10:00:00', 'end_datetime' => '2030-01-01 11:00:00']);
        DB::table('main.profesionista_disponibilidades')->insert(['profesionista_id' => 1, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00']);
        DB::table('main.profesionista_bloqueos')->insert(['profesionista_id' => 1, 'start_datetime' => '2030-01-01 10:00:00', 'end_datetime' => '2030-01-01 11:00:00']);
    }

    private function seedProtectedInfrastructure(User $user): void
    {
        DB::table('password_reset_tokens')->insert(['email' => 'synthetic-reset@example.test', 'token' => 'synthetic-not-a-real-credential', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $user->id, 'ip_address' => '192.0.2.1', 'user_agent' => 'Synthetic', 'payload' => base64_encode('synthetic'), 'last_activity' => now()->timestamp]);
        DB::table('cache')->insert(['key' => 'synthetic-cache', 'value' => serialize('synthetic'), 'expiration' => now()->addHour()->timestamp]);
        DB::table('cache_locks')->insert(['key' => 'synthetic-lock', 'owner' => 'synthetic-owner', 'expiration' => now()->addHour()->timestamp]);
        DB::table('jobs')->insert(['queue' => 'synthetic', 'payload' => '{"synthetic":true}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('job_batches')->insert(['id' => 'synthetic-batch', 'name' => 'Synthetic', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => null, 'cancelled_at' => null, 'created_at' => now()->timestamp, 'finished_at' => null]);
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'synthetic', 'queue' => 'synthetic', 'payload' => '{}', 'exception' => 'Synthetic failure', 'failed_at' => now()]);
    }
}
