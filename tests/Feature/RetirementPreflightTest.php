<?php

namespace Tests\Feature;

use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use App\Services\ProfilePhotoReferences;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\IdentityPhotoFixtures;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

#[Group('retirement-preflight-w62ca')]
class RetirementPreflightTest extends TestCase
{
    use IdentityPhotoFixtures, ReservationFixtures;

    private const TABLES = ['likes', 'comments', 'posts', 'reminders', 'emotions', 'citas',
        'profesionista_bloqueos', 'profesionista_disponibilidades', 'profesionistas'];

    private array $reviewedProtectedTriggers;

    protected function setUp(): void
    {
        parent::setUp();
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w62ca-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->reviewedProtectedTriggers = $this->protectedTriggers();
    }

    // Private to PHPUnit: never an application command, migration or general purge utility.
    private function memoryOnly(): void
    {
        $c = DB::connection();
        if (! app()->environment('testing') || $c->getDriverName() !== 'sqlite'
            || $c->getDatabaseName() !== ':memory:' || config('database.connections.'.$c->getName().'.url')) {
            throw new RuntimeException('Memory isolation required');
        }
        foreach ($c->select('PRAGMA database_list') as $db) {
            if ($db->file !== '' || ! in_array($db->name, ['main', 'temp'], true)) {
                throw new RuntimeException('Attached databases forbidden');
            }
        }
        if ((int) $c->selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            throw new RuntimeException('Foreign keys must remain enabled');
        }
    }

    private function protectedTriggers(): array
    {
        $this->memoryOnly();

        return collect(DB::select("SELECT name,tbl_name,sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name"))
            ->reject(fn ($trigger) => in_array($trigger->tbl_name, self::TABLES, true))
            ->map(fn ($trigger) => (array) $trigger)->all();
    }

    private function catalog(): array
    {
        $this->memoryOnly();
        $result = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $result[$table] = ['columns' => Schema::getColumns($table),
                'foreign_keys' => Schema::getForeignKeys($table), 'indexes' => Schema::getIndexes($table)];
        }

        return $result;
    }

    private function protectedRows(): array
    {
        $tables = array_values(array_diff(Schema::getTableListing(schemaQualified: false), self::TABLES));
        sort($tables);

        return $this->reservationRows($tables);
    }

    private function seedRetired(User $user, string $media = 'avatars/shared.png'): void
    {
        $this->memoryOnly();
        DB::table('profesionistas')->insert(['id' => 1, 'nombre' => 'Synthetic', 'email' => $user->email,
            'password' => 'synthetic-secret', 'cedula' => 'SYNTHETIC', 'google_access_token' => 'synthetic-token']);
        DB::table('posts')->insert(['id' => 1, 'user_id' => $user->id, 'media_path' => $media, 'deleted_at' => now()]);
        DB::table('comments')->insert(['id' => 1, 'user_id' => $user->id, 'post_id' => 1, 'content' => 'Synthetic', 'media_path' => $media]);
        DB::table('likes')->insert(['user_id' => $user->id, 'post_id' => 1, 'likeable_id' => 1, 'likeable_type' => 'App\\Models\\Post']);
        DB::table('reminders')->insert(['user_id' => $user->id, 'title' => 'Synthetic', 'remind_at' => '2030-01-01 10:00:00']);
        DB::table('emotions')->insert(['name' => 'Synthetic', 'slug' => 'synthetic', 'image_path' => $media]);
        DB::table('citas')->insert(['user_id' => $user->id, 'profesionista_id' => 1,
            'start_datetime' => '2030-01-01 10:00:00', 'end_datetime' => '2030-01-01 11:00:00']);
        DB::table('profesionista_disponibilidades')->insert(['profesionista_id' => 1, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00']);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => 1, 'start_datetime' => '2030-01-01 10:00:00', 'end_datetime' => '2030-01-01 11:00:00']);
    }

    private function simulate(array $order = self::TABLES, array $confirmedAbsent = [], ?int $failAfter = null): void
    {
        $this->memoryOnly();
        if (array_diff($order, self::TABLES) || count(array_unique($order)) !== count($order)) {
            throw new RuntimeException('Outside allowlist');
        }
        $catalog = $this->catalog();
        $absent = array_values(array_diff(self::TABLES, array_keys($catalog)));
        if ($absent !== $confirmedAbsent) {
            throw new RuntimeException('Unconfirmed partial schema');
        }
        if (DB::select('PRAGMA foreign_key_check')) {
            throw new RuntimeException('Inconsistent references');
        }
        foreach (DB::select("SELECT name, tbl_name FROM sqlite_master WHERE type = 'trigger'") as $trigger) {
            if (in_array($trigger->tbl_name, self::TABLES, true)) {
                throw new RuntimeException('Unexpected retired trigger');
            }
        }
        // Fail closed for ANY new/changed/missing protected trigger. Do not try to
        // infer SQL dependencies by substring matching or disable triggers. The
        // reviewed baseline is the fresh historical schema built in this test.
        if ($this->protectedTriggers() !== $this->reviewedProtectedTriggers) {
            throw new RuntimeException('Unreviewed protected trigger: stop before retirement');
        }
        $positions = array_flip($order);
        foreach ($catalog as $child => $definition) {
            foreach ($definition['foreign_keys'] as $fk) {
                $parent = $fk['foreign_table'];
                if (isset($positions[$parent]) && (! isset($positions[$child]) || $positions[$child] >= $positions[$parent])) {
                    throw new RuntimeException('Unsafe incoming dependency/order');
                }
            }
        }
        foreach (['posts' => 'media_path', 'comments' => 'media_path', 'emotions' => 'image_path'] as $table => $column) {
            if (isset($catalog[$table]) && ! Schema::hasColumn($table, $column)) {
                throw new RuntimeException('Incomplete reference schema');
            }
        }
        foreach ($order as $index => $table) {
            $this->memoryOnly();
            if (isset($catalog[$table])) {
                Schema::drop($table);
            }
            if ($failAfter === $index + 1) {
                throw new RuntimeException('Injected stop; no automatic resume');
            }
        }
    }

    public function test_inventory_comes_from_all_37_migrations_in_memory(): void
    {
        $this->assertDatabaseCount('migrations', 37);
        $catalog = $this->catalog();
        foreach (self::TABLES as $table) {
            $this->assertArrayHasKey($table, $catalog);
        }
        $edges = [];
        foreach ($catalog as $child => $row) {
            foreach ($row['foreign_keys'] as $fk) {
                $edges[] = ['child' => $child] + $fk;
                if (! in_array($child, self::TABLES, true)) {
                    $this->assertNotContains($fk['foreign_table'], self::TABLES);
                }
            }
        }
        if (getenv('TEKORA_PREFLIGHT_EVIDENCE') === '1') {
            file_put_contents(sys_get_temp_dir().'/tekora-w62ca-schema-'.getmypid().'.json', json_encode([
                'scope' => 'synthetic SQLite :memory: only', 'sqlite_version' => DB::selectOne('select sqlite_version() as version')->version,
                'tables' => $catalog, 'edges' => $edges,
                'triggers' => DB::select("SELECT name,tbl_name,sql FROM sqlite_master WHERE type = 'trigger'"),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_populated_retirement_preserves_every_protected_row_and_native_workflow(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $client->forceFill(['profile_photo_path' => 'avatars/shared.png'])->save();
        Storage::disk('public')->put('avatars/shared.png', 'synthetic-shared-bytes');
        $this->seedRetired($client);
        $client->createToken('Synthetic phone');
        $before = $this->protectedRows();
        $schema = array_diff_key($this->catalog(), array_flip(self::TABLES));
        $this->simulate();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSame($schema, $this->catalog());
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(app(ProfilePhotoReferences::class)->mayReference($client, 'avatars/shared.png'));
        $this->assertSame('synthetic-shared-bytes', Storage::disk('public')->get('avatars/shared.png'));
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), ['body' => 'Synthetic message', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(303);
        $this->post(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(303);
        $reservation = ServiceReservation::sole();
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc)->addMinute());
        $this->patch(route('reservations.complete', $reservation), ['version' => 2])->assertStatus(303);
        $this->actingAs($client, 'web')->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(303);
        $this->assertSame(1, ServiceReview::count());
        foreach (['account.client', 'notifications.index', 'conversations.index', 'catalog.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->post('/logout')->assertRedirect('/login');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => 'password', 'device_name' => 'Synthetic new phone'])->assertCreated()->json('data.access_token');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_parent_first_order_and_protected_target_are_rejected_before_any_drop(): void
    {
        $this->seedRetired(User::factory()->create());
        foreach ([array_reverse(self::TABLES), ['users']] as $order) {
            try {
                $this->simulate($order);
                $this->fail('Unsafe plan accepted');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/dependency|allowlist/', $e->getMessage());
            }
            foreach (self::TABLES as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
        }
    }

    public function test_unknown_inbound_fk_stops_before_any_drop(): void
    {
        Schema::create('synthetic_dependency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
        });
        $this->expectExceptionMessage('Unsafe incoming dependency/order');
        $this->simulate();
    }

    public function test_trigger_on_protected_users_referencing_posts_stops_before_any_retirement(): void
    {
        $user = User::factory()->create();
        $this->seedRetired($user);
        DB::unprepared("CREATE TRIGGER synthetic_cross_domain AFTER UPDATE OF name ON users BEGIN INSERT INTO posts (user_id, content) VALUES (NEW.id, 'synthetic cross-domain writer'); END");
        $triggers = $this->protectedTriggers();
        $before = $this->protectedRows();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->simulate();
            $this->fail('Cross-domain trigger accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Unreviewed protected trigger: stop before retirement', $e->getMessage());
        }
        $this->assertSame([], array_values(preg_grep('/^\s*drop\b/i', array_column(DB::getQueryLog(), 'query'))));
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame($before, $this->protectedRows());
        $this->assertSame($triggers, $this->protectedTriggers());
        $this->memoryOnly();
        // The trigger was neither removed nor disabled to obtain a green result.
        $user->update(['name' => 'Synthetic update after rejected plan']);
        $this->assertDatabaseCount('posts', 2);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_partial_failure_requires_explicit_checkpoint_and_preserves_protected_rows(): void
    {
        $this->seedRetired(User::factory()->create());
        $before = $this->protectedRows();
        try {
            $this->simulate(failAfter: 3);
            $this->fail('Failure not injected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Injected stop', $e->getMessage());
        }
        $this->assertSame($before, $this->protectedRows());
        try {
            $this->simulate();
            $this->fail('Unconfirmed resume accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Unconfirmed partial schema', $e->getMessage());
        }
        $this->simulate(confirmedAbsent: ['likes', 'comments', 'posts']);
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_sqlite_transactional_rehearsal_can_restore_synthetic_rows_after_failure(): void
    {
        $this->seedRetired(User::factory()->create());
        $before = $this->reservationRows(self::TABLES);
        try {
            DB::transaction(fn () => $this->simulate(failAfter: 2));
            $this->fail('Failure not injected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Injected stop', $e->getMessage());
        }
        $this->assertSame($before, $this->reservationRows(self::TABLES));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_logical_backup_restores_synthetic_schema_rows_and_triggers_to_a_separate_memory_database(): void
    {
        $this->seedRetired(User::factory()->create());
        $this->memoryOnly();
        $ddl = DB::select("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC,name");
        $rows = $this->reservationRows(Schema::getTableListing(schemaQualified: false));
        $checksum = hash('sha256', serialize([$ddl, $rows]));
        $this->simulate();
        $copy = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $copy->exec('PRAGMA foreign_keys = OFF');
        foreach ($ddl as $object) {
            if ($object->type === 'table') {
                $copy->exec($object->sql);
            }
        }
        $restoredRows = [];
        foreach ($rows as $table => $records) {
            foreach ($records as $row) {
                $columns = implode(',', array_map(fn ($c) => '"'.$c.'"', array_keys($row)));
                $statement = $copy->prepare('INSERT INTO "'.$table.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            }
            $restored = collect($copy->query('SELECT * FROM "'.$table.'"')->fetchAll(\PDO::FETCH_ASSOC))
                ->sortBy(fn ($row) => json_encode($row))->values()->all();
            $restoredRows[$table] = $restored;
            $this->assertSame($records, $restored, $table);
        }
        foreach ($ddl as $object) {
            if ($object->type !== 'table') {
                $copy->exec($object->sql);
            }
        }
        $copy->exec('PRAGMA foreign_keys = ON');
        $this->assertSame([], $copy->query('PRAGMA foreign_key_check')->fetchAll());
        $restoredDdl = $copy->query("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC,name")->fetchAll(\PDO::FETCH_OBJ);
        $this->assertSame($checksum, hash('sha256', serialize([$restoredDdl, $restoredRows])));
        $this->assertSame(count($ddl), (int) $copy->query("SELECT COUNT(*) FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'")->fetchColumn());
        $copy = null;
    }

    public static function drift(): array
    {
        return [['column'], ['trigger'], ['orphan'], ['foreign_keys_off'], ['attached'], ['file_config']];
    }

    #[DataProvider('drift')]
    public function test_inconsistent_schema_or_connection_is_a_stop(string $kind): void
    {
        if ($kind === 'column') {
            Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('media_path'));
        } elseif ($kind === 'trigger') {
            DB::unprepared('CREATE TRIGGER synthetic_old_writer AFTER INSERT ON posts BEGIN SELECT 1; END');
        } elseif ($kind === 'orphan') {
            Schema::disableForeignKeyConstraints();
            DB::table('posts')->insert(['user_id' => 99999]);
            Schema::enableForeignKeyConstraints();
        } elseif ($kind === 'foreign_keys_off') {
            Schema::disableForeignKeyConstraints();
        } elseif ($kind === 'attached') {
            DB::statement("ATTACH DATABASE ':memory:' AS synthetic_other");
        } else {
            // No file is opened: reject this config before obtaining any new PDO.
            DB::connection()->setDatabaseName('/nonexistent-synthetic-database');
        }
        $this->expectException(RuntimeException::class);
        $this->simulate();
    }

    public static function checkpoints(): array
    {
        return [[0], [1], [2], [3], [9]];
    }

    #[DataProvider('checkpoints')]
    public function test_shared_photo_survives_partial_and_complete_schema_retirement(int $count): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $path = 'avatars/avatar_'.$owner->id.'_synthetic.png';
        $owner->forceFill(['profile_photo_path' => $path])->save();
        $other->forceFill(['profile_photo_path' => 'https://example.invalid/storage/'.strtoupper($path).'?v=1'])->save();
        Storage::disk('public')->put($path, 'synthetic-shared-bytes');
        $this->seedRetired($owner, $path);
        $this->simulate(array_slice(self::TABLES, 0, $count));
        $this->actingAs($owner, 'web')->post('/perfil/foto', ['photo' => UploadedFile::fake()->createWithContent('new.png', $this->identityPhotoBytes())])->assertSessionHas('success');
        $this->assertNotSame($path, $owner->fresh()->profile_photo_path);
        $this->assertSame('synthetic-shared-bytes', Storage::disk('public')->get($path));
        $this->assertTrue(app(ProfilePhotoReferences::class)->mayReference($owner, $path));
    }
}
