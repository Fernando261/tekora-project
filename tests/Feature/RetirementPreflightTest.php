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
use Tests\Support\RetirementMemoryIntegrity;
use Tests\TestCase;

#[Group('retirement-preflight-w62ca')]
class RetirementPreflightTest extends TestCase
{
    use IdentityPhotoFixtures, ReservationFixtures, RetirementMemoryIntegrity;

    private const TABLES = ['likes', 'comments', 'posts', 'reminders', 'emotions', 'citas',
        'profesionista_bloqueos', 'profesionista_disponibilidades', 'profesionistas'];

    private array $reviewedProtectedTriggers;

    private array $reviewedManifest;

    private array $operationTrace = [];

    private ?array $observedCheckpoint = null;

    private string $syntheticStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w62ca-'.bin2hex(random_bytes(8));
        $this->syntheticStorageRoot = $temporary;
        app()->useStoragePath($temporary);
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->reviewedProtectedTriggers = $this->protectedTriggers();
        $this->reviewedManifest = $this->memoryManifest();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // PHPUnit retains test instances; do not retain complete DB snapshots
            // for every dataset until the end of the full regression suite.
            $this->reviewedManifest = [];
            $this->reviewedProtectedTriggers = [];
            $this->operationTrace = [];
            $this->observedCheckpoint = null;
        }
    }

    // Private to PHPUnit: never an application command, migration or general purge utility.
    private function memoryOnly(): void
    {
        $c = DB::connection();
        if (! defined('PHPUNIT_COMPOSER_INSTALL') || ! app()->environment('testing') || $c->getDriverName() !== 'sqlite'
            || $c->getDatabaseName() !== ':memory:' || config('database.connections.'.$c->getName().'.url')) {
            throw new RuntimeException('Memory isolation required');
        }
        $fakeRoot = str_replace('\\', '/', Storage::disk('public')->path(''));
        $expectedRoot = str_replace('\\', '/', $this->syntheticStorageRoot).'/framework/testing/disks/public/';
        if (is_file(base_path('.env')) || app()->storagePath() !== $this->syntheticStorageRoot
            || ! str_starts_with($fakeRoot, $expectedRoot)) {
            throw new RuntimeException('Synthetic environment and Storage fake required');
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

    private function verifyReviewedInventory(array $current, array $absent): void
    {
        if (DB::select('PRAGMA foreign_key_check')) {
            throw new RuntimeException('Inconsistent references');
        }
        $positions = array_flip(self::TABLES);
        foreach ($current['tables'] as $child => $definition) {
            foreach ($definition['foreign_keys'] as $fk) {
                $parent = $fk['table'];
                if (isset($positions[$parent]) && (! isset($positions[$child]) || $positions[$child] >= $positions[$parent])) {
                    throw new RuntimeException('Unsafe incoming dependency/order');
                }
            }
        }
        if ($this->protectedTriggers() !== $this->reviewedProtectedTriggers) {
            throw new RuntimeException('Unreviewed protected trigger: stop before retirement');
        }
        if ($this->schemaPart($current) !== $this->reviewedSchemaAt($absent)) {
            throw new RuntimeException('Unreviewed schema/object/column: stop before retirement');
        }
        if (isset($current['tables']['likes'])) {
            $unknown = DB::table('likes')->whereNotNull('likeable_type')
                ->whereNotIn('likeable_type', ['App\\Models\\Post', 'App\\Models\\Comment'])->exists();
            if ($unknown) {
                throw new RuntimeException('Unknown polymorphic dependency: stop before retirement');
            }
        }
        $this->protectedManifest($current);
        if ($current['tables']['migrations']['rows'] !== $this->reviewedManifest['tables']['migrations']['rows']) {
            throw new RuntimeException('Historical migration ledger mismatch');
        }
    }

    private function simulate(array $order = self::TABLES, ?array $checkpoint = null, ?int $failAfter = null, ?\Closure $beforeStep = null): void
    {
        $this->operationTrace = [];
        $this->memoryOnly();
        if (array_diff($order, self::TABLES) || count(array_unique($order)) !== count($order)) {
            throw new RuntimeException('Outside allowlist');
        }
        if ($order !== array_slice(self::TABLES, 0, count($order))) {
            throw new RuntimeException('Unsafe incoming dependency/order');
        }
        $current = $this->memoryManifest();
        $absent = array_values(array_diff(self::TABLES, array_keys($current['tables'])));
        if (($absent && $checkpoint === null) || ($checkpoint !== null && $checkpoint !== ['absent' => $absent, 'manifest' => $current])) {
            throw new RuntimeException('Unconfirmed partial schema');
        }
        $this->verifyReviewedInventory($current, $absent);
        $protected = $this->protectedManifest($current);
        foreach ($order as $index => $table) {
            $this->memoryOnly();
            if ($beforeStep) {
                $beforeStep($index + 1);
            }
            // Revalidate effective connection, all schema objects and FK before
            // EACH synthetic DROP, including after an injected request boundary.
            $this->memoryOnly();
            $current = $this->memoryManifest();
            $this->verifyReviewedInventory($current, $absent);
            if ($this->protectedManifest($current) !== $protected) {
                throw new RuntimeException('Protected state changed during rehearsal');
            }
            if (! in_array($table, $absent, true)) {
                Schema::drop($table);
                $absent[] = $table;
                $this->operationTrace[] = $table;
                $this->observedCheckpoint = ['absent' => $absent, 'manifest' => $this->memoryManifest()];
            }
            if ($failAfter === $index + 1) {
                throw new RuntimeException('Injected stop; no automatic resume');
            }
        }
        $this->verifyReviewedInventory($this->memoryManifest(), $absent);
        if ($this->protectedManifest($this->memoryManifest()) !== $protected) {
            throw new RuntimeException('Protected state changed after rehearsal');
        }
    }

    public function test_inventory_comes_from_all_37_migrations_in_memory(): void
    {
        $this->assertDatabaseCount('migrations', 37);
        $manifest = $this->memoryManifest();
        $this->assertCount(35, $manifest['tables']);
        $this->assertCount(26, $this->protectedManifest($manifest));
        $triggers = array_values(array_filter($manifest['objects'], fn ($o) => $o['type'] === 'trigger'));
        $this->assertCount(34, $triggers);
        $retiredTriggers = array_values(array_filter($triggers, fn ($o) => in_array($o['tbl_name'], self::TABLES, true)));
        $this->assertSame([], $retiredTriggers);
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
        $this->recordRehearsalEvidence('inventory', ['status' => 'PASS', 'migrations' => 37,
            'candidate_order' => self::TABLES, 'protected_tables' => self::PROTECTED,
            'foreign_key_edges' => $edges, 'all_triggers' => $triggers, 'candidate_triggers' => $retiredTriggers,
            'unknown_schema_objects' => [], 'sqlite_version' => DB::selectOne('select sqlite_version() as version')->version]);
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
        $this->message($client, $inquiry, 'Synthetic preserved outgoing message');
        $this->message($provider, $inquiry, 'Synthetic preserved received message');
        app(\App\Services\InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $existingReservation = $this->proposeReservation($inquiry);
        app(\App\Services\ReservationWorkflow::class)->decide($provider, $existingReservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(\Carbon\Carbon::parse($existingReservation->ends_at_utc, 'UTC'));
        app(\App\Services\ReservationWorkflow::class)->decide($provider, $existingReservation->id, 'complete', ['version' => 2]);
        app(\App\Services\ReviewWorkflow::class)->publish($client, $existingReservation->id, ['rating' => 5]);
        DB::table('provider_blocked_dates')->insert(['provider_profile_id' => $provider->providerProfile->id, 'blocked_date' => '2030-12-25']);
        $this->seedProtectedInfrastructure($client);
        $manifestBefore = $this->memoryManifest();
        foreach (self::PROTECTED as $table) {
            $this->assertNotEmpty($manifestBefore['tables'][$table]['rows'], $table);
        }
        $before = $this->protectedRows();
        $schema = array_diff_key($this->catalog(), array_flip(self::TABLES));
        $this->simulate();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSame($schema, $this->catalog());
        $manifestAfter = $this->memoryManifest();
        $this->assertSame($this->protectedManifest($manifestBefore), $this->protectedManifest($manifestAfter));
        $this->assertSame(self::PROTECTED, array_keys($manifestAfter['tables']));
        $this->recordRehearsalEvidence('complete-preservation', ['status' => 'PASS',
            'before' => $this->manifestSummary($manifestBefore), 'after' => $this->manifestSummary($manifestAfter),
            'protected_tables_exactly_equal' => self::PROTECTED, 'operations' => $this->operationTrace,
            'all_26_protected_tables_populated' => true,
            'workflows_started_only_after_preservation_comparison' => true]);
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(app(ProfilePhotoReferences::class)->mayReference($client, 'avatars/shared.png'));
        $this->assertSame('synthetic-shared-bytes', Storage::disk('public')->get('avatars/shared.png'));
        $inquiry = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), ['body' => 'Synthetic message', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(303);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['date' => '2030-01-08']))->assertStatus(303);
        $reservation = ServiceReservation::where('service_inquiry_id', $inquiry->id)->sole();
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc)->addMinute());
        $this->patch(route('reservations.complete', $reservation), ['version' => 2])->assertStatus(303);
        $this->actingAs($client, 'web')->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(303);
        $this->assertSame(1, ServiceReview::where('service_reservation_id', $reservation->id)->count());
        $this->assertSame(2, ServiceReview::count());
        foreach (['account.client', 'notifications.index', 'conversations.index', 'catalog.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->post('/logout')->assertRedirect('/login');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => 'password', 'device_name' => 'Synthetic new phone'])->assertCreated()->json('data.access_token');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
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
        $checkpoint = $this->observedCheckpoint;
        try {
            $this->simulate();
            $this->fail('Unconfirmed resume accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Unconfirmed partial schema', $e->getMessage());
        }
        $this->simulate(checkpoint: $checkpoint);
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
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->seedRetired($client);
        $reservation = $this->proposeReservation($inquiry);
        $this->message($client, $inquiry, 'Synthetic restored outgoing message');
        $this->message($provider, $inquiry, 'Synthetic restored received message');
        app(\App\Services\InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        app(\App\Services\ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(\App\Services\ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        app(\App\Services\ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 5]);
        $client->createToken('Synthetic restored device');
        DB::table('provider_blocked_dates')->insert(['provider_profile_id' => $provider->providerProfile->id, 'blocked_date' => '2030-12-25']);
        $this->seedProtectedInfrastructure($client);
        $client->forceFill(['profile_photo_path' => 'avatars/shared.png'])->save();
        Storage::disk('public')->put('avatars/shared.png', 'synthetic-restore-photo');
        // Preserve a high-water mark above MAX(id), not just populated row IDs.
        $unused = User::factory()->create();
        $unused->delete();
        DB::statement("INSERT INTO cache(key,value,expiration) VALUES ('synthetic-restored-blob',CAST('synthetic binary value' AS BLOB),123)");
        $source = $this->memoryManifest();
        foreach (self::PROTECTED as $table) {
            $this->assertNotEmpty($source['tables'][$table]['rows'], $table);
        }
        $this->simulate();
        $copy = $this->restoreLogicalMemory($source);
        $this->assertNotSame(DB::connection()->getPdo(), $copy);
        $this->assertSame($source, $this->memoryManifest($copy));
        $this->assertSame([], $copy->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame(1, (int) $copy->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(37, (int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM service_reservations r JOIN reservation_events e ON e.service_reservation_id=r.id AND e.version=r.version JOIN service_inquiries i ON i.id=r.service_inquiry_id JOIN services s ON s.id=r.service_id JOIN provider_profiles p ON p.id=r.provider_profile_id WHERE i.client_user_id=r.client_user_id AND s.provider_profile_id=p.id')->fetchColumn());
        $photo = $copy->query('SELECT profile_photo_path FROM users WHERE id='.(int) $client->id)->fetchColumn();
        $this->assertSame('avatars/shared.png', $photo);
        $this->assertSame('synthetic-restore-photo', Storage::disk('public')->get($photo));
        foreach ([
            'UPDATE reservation_events SET version=99',
            'INSERT INTO provider_weekly_intervals(provider_profile_id,day_of_week,start_minute,end_minute) VALUES ('.(int) $provider->providerProfile->id.',1,540,600)',
            "INSERT INTO service_inquiries(service_id,client_user_id,service_title,message,status) SELECT service_id,client_user_id,'Synthetic','Synthetic','pending' FROM service_inquiries LIMIT 1",
        ] as $invalidSql) {
            try {
                $this->guardMemoryPdo($copy);
                $copy->exec($invalidSql);
                $this->fail('Restored native constraint inactive');
            } catch (\PDOException $exception) {
                $this->assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
            $this->assertSame($source, $this->memoryManifest($copy));
        }
        $copy->beginTransaction();
        $nextUser = $source['tables']['users']['rows'][0]['values'];
        unset($nextUser['id']);
        $nextUser['email'] = 'synthetic-restored@example.test';
        $statement = $copy->prepare('INSERT INTO users ('.implode(',', array_map($this->quoteIdentifier(...), array_keys($nextUser))).') VALUES ('.implode(',', array_fill(0, count($nextUser), '?')).')');
        $statement->execute(array_values($nextUser));
        $statement->closeCursor();
        $this->assertSame($source['tables']['users']['sequence'] + 1, (int) $copy->lastInsertId());
        $copy->rollBack();
        $this->assertSame($source, $this->memoryManifest($copy));
        $this->recordRehearsalEvidence('logical-restore', ['status' => 'PASS', 'source' => $this->manifestSummary($source),
            'destination_manifest_equal' => true, 'independent_memory_pdo' => true, 'foreign_keys_always_on' => true,
            'native_relations_verified' => true, 'synthetic_photo_bytes_equal' => true, 'physical_backup_certification' => false]);
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
            DB::beginTransaction();
            DB::statement('PRAGMA defer_foreign_keys = ON');
            DB::table('posts')->insert(['user_id' => 99999]);
            $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        } elseif ($kind === 'foreign_keys_off') {
            // A brand-new, empty memory PDO starts with FK disabled. Never turn
            // enforcement off on the migrated connection or perform DDL here.
            DB::connection()->setPdo(new \PDO('sqlite::memory:'));
        } elseif ($kind === 'attached') {
            DB::statement("ATTACH DATABASE ':memory:' AS synthetic_other");
        } else {
            // No file is opened: reject this config before obtaining any new PDO.
            DB::connection()->setDatabaseName('/nonexistent-synthetic-database');
        }
        try {
            $this->expectException(RuntimeException::class);
            $this->simulate();
        } finally {
            if ($kind === 'orphan') {
                DB::rollBack();
            }
        }
    }

    public static function additionalDrift(): array
    {
        return array_map(fn ($kind) => [$kind], ['new-trigger', 'modified-trigger', 'missing-trigger',
            'cross-view', 'temp-object', 'protected-fk', 'missing-candidate', 'missing-protected',
            'missing-column', 'unknown-index', 'unknown-table', 'missing-migration', 'changed-batch']);
    }

    #[DataProvider('additionalDrift')]
    public function test_unreviewed_dependencies_stop_before_the_first_drop_and_leave_the_observed_state_intact(string $kind): void
    {
        $this->seedRetired(User::factory()->create());
        $this->memoryOnly();
        match ($kind) {
            'new-trigger' => DB::unprepared('CREATE TRIGGER synthetic_new AFTER INSERT ON users BEGIN SELECT 1; END'),
            'modified-trigger', 'missing-trigger' => DB::unprepared('DROP TRIGGER provider_weekly_no_overlap_insert'),
            'cross-view' => DB::unprepared('CREATE VIEW synthetic_cross AS SELECT users.id FROM users JOIN posts ON users.id=posts.user_id'),
            'temp-object' => DB::unprepared('CREATE TEMP VIEW synthetic_temp AS SELECT id FROM posts'),
            'protected-fk' => DB::statement('ALTER TABLE users ADD COLUMN synthetic_post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE'),
            'missing-candidate' => Schema::drop('emotions'),
            'missing-protected' => Schema::drop('cache_locks'),
            'missing-column' => DB::statement('ALTER TABLE users DROP COLUMN profile_photo_path'),
            'unknown-index' => DB::statement('CREATE INDEX synthetic_unknown ON users(name)'),
            'unknown-table' => DB::statement('CREATE TABLE synthetic_unknown (id INTEGER PRIMARY KEY)'),
            'missing-migration' => DB::table('migrations')->where('id', 1)->delete(),
            'changed-batch' => DB::table('migrations')->where('id', 1)->update(['batch' => 99]),
        };
        if ($kind === 'modified-trigger') {
            DB::unprepared('CREATE TRIGGER provider_weekly_no_overlap_insert BEFORE INSERT ON provider_weekly_intervals BEGIN SELECT 1; END');
        }
        $before = $this->memoryManifest();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->simulate();
            $this->fail('Unreviewed state accepted');
        } catch (RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/Unreviewed|Unsafe|Unconfirmed|ledger|Exact 26/', $exception->getMessage());
        }
        $this->assertSame([], $this->operationTrace);
        $this->assertSame([], array_values(preg_grep('/^\s*drop\b/i', array_column(DB::getQueryLog(), 'query'))));
        $this->assertSame($before, $this->memoryManifest());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->recordRehearsalEvidence('stop-'.$kind, ['status' => 'PASS', 'rejected_before_first_drop' => true,
            'observed_state_unchanged' => true, 'drop_statements' => 0, 'foreign_keys_on' => true]);
    }

    public static function manifestMutations(): array
    {
        return array_map(fn ($kind) => [$kind], ['same-count-value', 'null', 'date', 'storage-type', 'sequence', 'batch', 'empty-table', 'partial-index']);
    }

    #[DataProvider('manifestMutations')]
    public function test_manifest_detects_changes_beyond_row_counts_including_storage_types_and_empty_tables(string $kind): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('cache')->insert(['key' => 'synthetic-type', 'value' => '1', 'expiration' => 123]);
        $before = $this->memoryManifest();
        $this->memoryOnly();
        match ($kind) {
            'same-count-value' => $user->update(['name' => 'Synthetic changed value']),
            'null' => DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]),
            'date' => DB::table('users')->where('id', $user->id)->update(['created_at' => '2001-01-01 00:00:00']),
            'storage-type' => DB::statement("UPDATE cache SET value=CAST('1' AS BLOB) WHERE key='synthetic-type'"),
            'sequence' => DB::statement("UPDATE sqlite_sequence SET seq=seq+7 WHERE name='users'"),
            'batch' => DB::table('migrations')->where('id', 1)->update(['batch' => 2]),
            'empty-table' => Schema::drop('cache_locks'),
            'partial-index' => DB::statement('DROP INDEX service_inquiries_one_active'),
        };
        if ($kind === 'partial-index') {
            DB::statement("CREATE UNIQUE INDEX service_inquiries_one_active ON service_inquiries(service_id,client_user_id) WHERE status='pending'");
        }
        $after = $this->memoryManifest();
        $this->assertNotSame($before, $after);
        if ($kind !== 'empty-table') {
            $this->assertSame(array_map(fn ($table) => count($table['rows']), $before['tables']), array_map(fn ($table) => count($table['rows']), $after['tables']));
        } else {
            $this->assertSame([], $before['tables']['cache_locks']['rows']);
            $this->assertArrayNotHasKey('cache_locks', $after['tables']);
        }
        if ($kind === 'storage-type') {
            $this->assertSame($before['tables']['cache']['rows'][0]['values'], $after['tables']['cache']['rows'][0]['values']);
            $this->assertNotSame($before['tables']['cache']['rows'][0]['storage_types'], $after['tables']['cache']['rows'][0]['storage_types']);
        }
        $this->recordRehearsalEvidence('manifest-'.$kind, ['status' => 'PASS', 'mutation_detected' => true,
            'row_counts_equal' => $kind !== 'empty-table', 'no_table_or_column_exclusion' => true]);
    }

    public static function rollbackStops(): array
    {
        return [[1, false], [3, false], [9, false], [9, true]];
    }

    #[DataProvider('rollbackStops')]
    public function test_every_rollback_is_observed_inside_and_after_the_transaction(int $step, bool $beforeCommit): void
    {
        [$client] = $this->reservationContext();
        $this->seedRetired($client);
        $before = $this->memoryManifest();
        $observed = null;
        try {
            DB::transaction(function () use ($step, $beforeCommit, &$observed, $before) {
                try {
                    $this->simulate(failAfter: $beforeCommit ? null : $step);
                    throw new RuntimeException('Injected before commit');
                } catch (RuntimeException $exception) {
                    $inside = $this->memoryManifest();
                    $observed = array_values(array_diff(self::TABLES, array_keys($inside['tables'])));
                    $this->assertSame(array_slice(self::TABLES, 0, $step), $observed);
                    $this->assertSame($observed, $this->operationTrace);
                    $this->assertSame($this->protectedManifest($before), $this->protectedManifest($inside));
                    $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                    throw $exception;
                }
            });
            $this->fail('Fault injection did not stop transaction');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Injected', $exception->getMessage());
        }
        $this->assertNotNull($observed);
        $this->assertSame($before, $this->memoryManifest());
        foreach (self::TABLES as $table) {
            $this->assertNotEmpty($before['tables'][$table]['rows'], $table);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->recordRehearsalEvidence('rollback-'.$step.($beforeCommit ? '-before-commit' : ''), ['status' => 'PASS',
            'observed_absent_inside_transaction' => $observed, 'all_nine_rows_and_tables_restored' => true,
            'entire_manifest_restored' => true, 'automatic_resume' => false, 'physical_crash_simulation' => false]);
    }

    public static function boundaryDrift(): array
    {
        return [['connection'], ['trigger'], ['row'], ['attached']];
    }

    #[DataProvider('boundaryDrift')]
    public function test_guards_are_rechecked_before_each_drop_not_only_before_the_plan(string $kind): void
    {
        $user = User::factory()->create();
        $this->seedRetired($user);
        $before = $this->memoryManifest();
        try {
            DB::transaction(fn () => $this->simulate(beforeStep: function (int $step) use ($kind, $user) {
                if ($step !== 2) {
                    return;
                }
                match ($kind) {
                    'connection' => DB::connection()->setDatabaseName('/synthetic-never-opened'),
                    'trigger' => DB::unprepared('CREATE TRIGGER synthetic_boundary AFTER UPDATE ON users BEGIN SELECT 1; END'),
                    'row' => $user->update(['name' => 'Synthetic interleaved write']),
                    'attached' => DB::statement("ATTACH DATABASE ':memory:' AS synthetic_boundary"),
                };
            }));
            $this->fail('Boundary drift accepted');
        } catch (RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/isolation|Attached|Unreviewed|Protected/', $exception->getMessage());
        } finally {
            if ($kind === 'connection') {
                DB::connection()->setDatabaseName(':memory:');
            }
            if ($kind === 'attached') {
                DB::statement('DETACH DATABASE synthetic_boundary');
            }
        }
        $this->assertSame(['likes'], $this->operationTrace);
        $this->assertSame($before, $this->memoryManifest());
        $this->recordRehearsalEvidence('boundary-'.$kind, ['status' => 'PASS', 'executed_drops' => ['likes'],
            'second_drop_rejected' => true, 'transaction_restored' => true, 'persistent_connection_opened' => false]);
    }

    public function test_committed_partial_state_rejects_a_forged_or_stale_checkpoint_without_automatic_resume(): void
    {
        $user = User::factory()->create();
        $this->seedRetired($user);
        try {
            $this->simulate(failAfter: 3);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Injected', $exception->getMessage());
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $checkpoint = $this->observedCheckpoint;
        $this->assertSame(['likes', 'comments', 'posts'], $checkpoint['absent']);
        $user->update(['name' => 'Synthetic change after confirmed checkpoint']);
        $state = $this->memoryManifest();
        foreach ([null, ['absent' => $checkpoint['absent'], 'manifest' => []], $checkpoint] as $confirmation) {
            try {
                $this->simulate(checkpoint: $confirmation);
                $this->fail('Unverified checkpoint accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unconfirmed partial schema', $exception->getMessage());
            }
            $this->assertSame([], $this->operationTrace);
            $this->assertSame($state, $this->memoryManifest());
        }
        $this->recordRehearsalEvidence('partial-checkpoint', ['status' => 'PASS', 'committed_absent' => $checkpoint['absent'],
            'missing_forged_and_stale_confirmation_rejected' => true, 'automatic_resume' => false]);
    }

    public static function restoreMutations(): array
    {
        return [['row'], ['sequence'], ['index'], ['trigger'], ['unknown-object']];
    }

    #[DataProvider('restoreMutations')]
    public function test_logical_restore_rejects_drift_and_rolls_back_the_new_memory_destination(string $kind): void
    {
        $this->seedRetired(User::factory()->create());
        $source = $this->memoryManifest();
        $destination = null;
        try {
            $this->restoreLogicalMemory($source, function (\PDO $copy) use ($kind, &$destination) {
                $destination = $copy;
                $this->guardMemoryPdo($copy);
                match ($kind) {
                    'row' => $copy->exec("UPDATE users SET name='Synthetic corrupt restore'"),
                    'sequence' => $copy->exec("UPDATE sqlite_sequence SET seq=seq+5 WHERE name='users'"),
                    'index' => $copy->exec('DROP INDEX service_inquiries_one_active'),
                    'trigger' => $copy->exec('DROP TRIGGER provider_weekly_no_overlap_insert'),
                    'unknown-object' => $copy->exec('CREATE VIEW synthetic_unknown AS SELECT id FROM users'),
                };
            });
            $this->fail('Corrupt restore accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Restored manifest mismatch; destination rejected', $exception->getMessage());
        }
        $this->assertInstanceOf(\PDO::class, $destination);
        $this->guardMemoryPdo($destination);
        $this->assertSame(0, (int) $destination->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn());
        $this->assertSame($source, $this->memoryManifest());
        $this->recordRehearsalEvidence('restore-reject-'.$kind, ['status' => 'PASS', 'destination_rejected' => true,
            'destination_ddl_and_rows_rolled_back' => true, 'source_manifest_unchanged' => true, 'foreign_keys_always_on' => true]);
    }

    public static function unreviewedRestoreSources(): array
    {
        return [['object'], ['ddl'], ['ledger']];
    }

    #[DataProvider('unreviewedRestoreSources')]
    public function test_restore_rejects_unreviewed_source_before_creating_any_destination_object(string $kind): void
    {
        $before = $this->memoryManifest();
        $source = $before;
        if ($kind === 'object') {
            $source['objects'][] = ['type' => 'view', 'name' => 'synthetic_unknown', 'tbl_name' => 'synthetic_unknown', 'sql' => 'CREATE VIEW synthetic_unknown AS SELECT id FROM users'];
        } elseif ($kind === 'ddl') {
            $source['tables']['users']['sql'] = 'CREATE TABLE synthetic_unreviewed(id INTEGER PRIMARY KEY)';
        } else {
            $source['tables']['migrations']['rows'][0]['values']['batch'] = 99;
        }
        $loaded = false;
        try {
            $this->restoreLogicalMemory($source, function () use (&$loaded) {
                $loaded = true;
            });
            $this->fail('Unreviewed restore source accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unreviewed restore schema', $exception->getMessage());
        }
        $this->assertFalse($loaded);
        $this->assertSame($before, $this->memoryManifest());
        $this->recordRehearsalEvidence('restore-source-'.$kind, ['status' => 'PASS', 'rejected_before_destination_creation' => true,
            'source_connection_unchanged' => true]);
    }

    private function retiredNativeContext(): array
    {
        $context = $this->reservationContext();
        $this->seedRetired($context[0]);
        $before = $this->protectedManifest($this->memoryManifest());
        DB::transaction(fn () => $this->simulate());
        $this->assertSame($before, $this->protectedManifest($this->memoryManifest()));

        // Workflow writes start only after preservation has been established.
        return $context;
    }

    public function test_native_auth_roles_new_inquiries_messages_monotonic_reads_and_api_after_retirement(): void
    {
        [$client, $provider, $inquiry] = $this->retiredNativeContext();
        $this->assertTrue($client->hasRole(\App\Enums\RoleName::Client));
        $this->post('/login', ['email' => $client->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($client, 'web');
        $this->get(route('account.provider'))->assertForbidden();
        $service = $this->service($provider);
        $this->actingAs($client, 'web')->post(route('inquiries.store', $service), ['message' => 'Synthetic new native request'])->assertStatus(303);
        $created = \App\Models\ServiceInquiry::where('service_id', $service->id)->sole();
        $this->actingAs($provider, 'web')->patch(route('inquiries.accept', $created))->assertStatus(303);
        $this->assertSame('accepted', $created->fresh()->status->value);
        $data = $this->messageData('Synthetic idempotent message');
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $data)->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $data)->assertStatus(303);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $outsider = $this->account(\App\Enums\RoleName::Client);
        $beforeDenied = $this->protectedManifest($this->memoryManifest());
        $this->actingAs($outsider, 'web')->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertForbidden();
        $this->assertSame($beforeDenied, $this->protectedManifest($this->memoryManifest()));
        $this->message($provider, $inquiry, 'Synthetic first reply');
        $oldToken = $this->readToken($client, $inquiry);
        $latest = $this->message($provider, $inquiry, 'Synthetic second reply');
        $newToken = $this->readToken($client, $inquiry);
        $this->actingAs($client, 'web')->post(route('conversations.read', $inquiry), ['read_token' => $newToken])->assertStatus(303);
        $marker = \App\Models\InquiryReadProgress::sole()->getAttributes();
        $this->travel(1)->minute();
        $this->post(route('conversations.read', $inquiry), ['read_token' => $oldToken])->assertStatus(303);
        $this->assertSame($latest->id, $marker['last_read_message_id']);
        $this->assertSame($marker, \App\Models\InquiryReadProgress::sole()->getAttributes());
        $this->assertSame(0, app(\App\Services\InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->post('/logout')->assertRedirect('/login');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => 'password', 'device_name' => 'Synthetic API rehearsal'])->assertCreated()->json('data.access_token');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.id', $client->id)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/messages', $headers)->assertOk()->assertJsonCount(3, 'data')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/services')->assertOk();
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->recordRehearsalEvidence('workflow-auth-conversations-api', ['status' => 'PASS', 'after_retirement' => true,
            'new_inquiry_and_acceptance' => true, 'authorization_denial_unchanged' => true, 'message_replay_single_row' => true,
            'stale_read_retains_marker_and_timestamp' => true, 'api_identity_messages_catalog_contracts' => true]);
    }

    public function test_native_availability_reservations_reviews_and_notifications_after_retirement(): void
    {
        [$client, $provider, $inquiry] = $this->retiredNativeContext();
        $this->publishSchedule($provider, 'UTC', 1);
        $this->assertSame(2, $provider->providerProfile->availabilitySetting()->sole()->revision);
        $workflow = app(\App\Services\ReservationWorkflow::class);
        try {
            $workflow->propose($client, $inquiry->id, $this->reservationData());
            $this->fail('Stale availability accepted');
        } catch (\App\Exceptions\ReservationFailure $exception) {
            $this->assertSame(409, $exception->status);
        }
        $data = $this->reservationData(['availability_revision' => 2]);
        $first = $workflow->propose($client, $inquiry->id, $data);
        $replay = $workflow->propose($client, $inquiry->id, $data);
        $this->assertTrue($first['created']);
        $this->assertFalse($replay['created']);
        $this->assertSame($first['reservation']->id, $replay['reservation']->id);
        $reservation = $first['reservation'];
        $unchanged = $this->protectedManifest($this->memoryManifest());
        $this->actingAs($client, 'web')->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertForbidden();
        $this->actingAs($provider, 'web')->patchJson(route('reservations.confirm', $reservation), ['version' => 99])->assertConflict();
        $this->assertSame($unchanged, $this->protectedManifest($this->memoryManifest()));
        try {
            DB::table('provider_weekly_intervals')->insert(['provider_profile_id' => $provider->providerProfile->id, 'day_of_week' => 1, 'start_minute' => 540, 'end_minute' => 600]);
            $this->fail('Native overlap trigger inactive');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Overlapping weekly intervals', $exception->getMessage());
        }
        $this->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc, 'UTC'));
        $this->patch(route('reservations.complete', $reservation), ['version' => 2])->assertStatus(303);
        $this->assertSame(3, $reservation->fresh()->version);
        $this->assertSame([1, 2, 3], $reservation->events()->orderBy('version')->pluck('version')->all());
        $this->actingAs($client, 'web')->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(303);
        $this->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(303);
        $review = ServiceReview::sole();
        $this->patch(route('reviews.withdraw', $review))->assertStatus(303);
        $firstWithdrawal = $review->fresh()->getAttributes();
        $this->travel(1)->minute();
        $this->patch(route('reviews.withdraw', $review))->assertStatus(303);
        $this->assertSame($firstWithdrawal, $review->fresh()->getAttributes());
        $this->assertDatabaseCount('service_reservations', 1);
        $this->assertDatabaseCount('reservation_events', 3);
        $this->assertDatabaseCount('activity_notifications', 5);
        $this->assertSame(['reservation_requested', 'reservation_confirmed', 'reservation_completed', 'review_published', 'review_withdrawn'], DB::table('activity_notifications')->orderBy('id')->pluck('event')->all());
        $this->assertSame([$provider->id, $client->id, $client->id, $provider->id, $provider->id], DB::table('activity_notifications')->orderBy('id')->pluck('user_id')->all());
        $notice = \App\Models\ActivityNotification::where('user_id', $client->id)->firstOrFail();
        $this->post(route('notifications.activity.read', $notice))->assertStatus(303);
        $firstRead = $notice->fresh()->getAttributes();
        $this->travel(1)->minute();
        $this->post(route('notifications.activity.read', $notice))->assertStatus(303);
        $this->assertSame($firstRead, $notice->fresh()->getAttributes());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->recordRehearsalEvidence('workflow-reservations-reviews-notifications', ['status' => 'PASS', 'after_retirement' => true,
            'availability_revision_and_overlap' => true, 'reservation_replay' => true, 'authorization_and_stale_version' => true,
            'versions_and_events' => [1, 2, 3], 'review_publication_and_withdrawal_idempotent' => true,
            'notification_count' => 5, 'recipients_verified' => true, 'first_read_timestamp_preserved' => true]);
    }

    public function test_native_notification_failure_rolls_back_reservation_and_event_after_retirement(): void
    {
        [, $provider, $inquiry] = $this->retiredNativeContext();
        $reservation = $this->proposeReservation($inquiry);
        DB::unprepared("CREATE TRIGGER synthetic_notice_failure BEFORE INSERT ON activity_notifications WHEN NEW.event='reservation_confirmed' BEGIN SELECT RAISE(ABORT, 'Synthetic notification failure'); END");
        $before = $this->protectedManifest($this->memoryManifest());
        try {
            app(\App\Services\ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
            $this->fail('Notification failure was ignored');
        } catch (\App\Exceptions\ReservationFailure $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('SQL', $exception->getMessage());
        }
        $this->assertSame($before, $this->protectedManifest($this->memoryManifest()));
        $this->assertSame('requested', $reservation->fresh()->status->value);
        $this->assertDatabaseCount('reservation_events', 1);
        $this->assertDatabaseCount('activity_notifications', 1);
        $this->assertSame(0, DB::transactionLevel());
        $this->recordRehearsalEvidence('workflow-atomic-failure', ['status' => 'PASS', 'after_retirement' => true,
            'reservation_event_notification_rollback_exact' => true, 'fault_trigger_not_disabled' => true]);
    }

    public static function photoScenarios(): array
    {
        return array_map(fn ($kind) => [$kind], ['native-only', 'legacy-only', 'shared', 'soft-deleted', 'normalized',
            'external-url', 'unknown-morph', 'legacy-absent', 'query-error', 'storage-error']);
    }

    #[DataProvider('photoScenarios')]
    public function test_synthetic_photo_references_and_uncertainty_never_touch_real_media(string $kind): void
    {
        $owner = User::factory()->create();
        $path = 'avatars/avatar_'.$owner->id.'_synthetic.png';
        $disk = Storage::disk('public');
        $disk->put($path, 'synthetic-only-photo-bytes');
        $owner->forceFill(['profile_photo_path' => in_array($kind, ['legacy-only', 'soft-deleted', 'normalized'], true) ? null : $path])->save();
        $this->seedRetired($owner, $kind === 'native-only' ? 'unrelated-synthetic.png' : $path);
        if (in_array($kind, ['shared', 'external-url', 'legacy-absent'], true)) {
            User::factory()->create(['profile_photo_path' => 'https://example.invalid/storage/'.strtoupper($path).'?synthetic=1#photo']);
        }
        if ($kind === 'normalized') {
            DB::table('posts')->update(['media_path' => 'public/'.strtoupper($path)]);
            DB::table('comments')->update(['media_path' => null]);
            DB::table('emotions')->update(['image_path' => 'unrelated-synthetic.png']);
        }
        if ($kind === 'soft-deleted') {
            $this->assertNotNull(DB::table('posts')->value('deleted_at'));
            DB::table('comments')->update(['deleted_at' => now()]);
        }
        $this->assertTrue(app(ProfilePhotoReferences::class)->mayReference($owner, $path));
        if ($kind === 'unknown-morph') {
            DB::table('likes')->update(['likeable_type' => 'App\\Models\\UnknownSynthetic']);
            $before = $this->memoryManifest();
            try {
                $this->simulate();
                $this->fail('Unknown morph accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unknown polymorphic dependency: stop before retirement', $exception->getMessage());
            }
            $this->assertSame([], $this->operationTrace);
            $this->assertSame($before, $this->memoryManifest());
        } else {
            $before = $this->protectedManifest($this->memoryManifest());
            $this->simulate();
            $this->assertSame($before, $this->protectedManifest($this->memoryManifest()));
        }
        if ($kind === 'query-error') {
            $this->instance(\App\Services\LegacyPhotoReferences::class, \Mockery::mock(\App\Services\LegacyPhotoReferences::class, function ($mock) {
                $mock->shouldReceive('mayReference')->once()->andThrow(new RuntimeException('Synthetic query failure'));
            }));
        }
        if ($kind === 'storage-error') {
            $proxy = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
            $proxy->shouldReceive('get')->once()->andThrow(new RuntimeException('Synthetic storage failure'));
            Storage::set('public', $proxy);
        }
        if (in_array($kind, ['shared', 'external-url', 'legacy-absent', 'query-error', 'storage-error'], true)) {
            $response = $this->actingAs($owner, 'web')->post('/perfil/foto', ['photo' => UploadedFile::fake()->createWithContent('new.png', $this->identityPhotoBytes())]);
            if ($kind === 'storage-error') {
                $response->assertSessionHasErrors('photo');
                $this->assertSame($path, $owner->fresh()->profile_photo_path);
            } else {
                $response->assertSessionHas('success');
                $this->assertNotSame($path, $owner->fresh()->profile_photo_path);
            }
        }
        $this->assertSame('synthetic-only-photo-bytes', $disk->get($path));
        Http::assertNothingSent();
        $this->recordRehearsalEvidence('photo-'.$kind, ['status' => 'PASS', 'synthetic_bytes_retained' => true,
            'retirement_rejected_for_unknown_morph' => $kind === 'unknown-morph', 'storage_fake' => true,
            'external_requests' => 0, 'real_media_reads' => 0, 'media_hashes_published' => false]);
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
