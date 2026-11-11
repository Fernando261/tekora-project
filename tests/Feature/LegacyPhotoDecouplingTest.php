<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyPhotoReferences;
use App\Services\ProfilePhotoReferences;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\IdentityPhotoFixtures;
use Tests\Support\WithoutLegacySchema;
use Tests\TestCase;

#[Group('legacy-decoupling-w62a')]
class LegacyPhotoDecouplingTest extends TestCase
{
    use IdentityPhotoFixtures, WithoutLegacySchema;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w62a-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
        $this->disk = Storage::fake('public');
        Log::spy();
    }

    private function photo(User $user): string
    {
        $path = 'avatars/avatar_'.$user->id.'_synthetic.png';
        $user->forceFill(['profile_photo_path' => $path])->save();
        $this->disk->put($path, 'synthetic-old-photo');

        return $path;
    }

    private function upload(User $user)
    {
        return $this->actingAs($user, 'web')->from('/perfil')->post('/perfil/foto', [
            'photo' => UploadedFile::fake()->createWithContent('new.png', $this->identityPhotoBytes()),
        ]);
    }

    private function assertCommitted(User $user): string
    {
        $path = $user->fresh()->profile_photo_path;
        $this->assertSame($this->identityPhotoBytes(), $this->disk->get($path));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());

        return $path;
    }

    public static function absentTables(): array
    {
        $sets = [];
        for ($mask = 0; $mask < 8; $mask++) {
            $tables = [];
            foreach (['comments', 'posts', 'emotions'] as $bit => $table) {
                if ($mask & (1 << $bit)) {
                    $tables[] = $table;
                }
            }
            $sets['missing-'.($tables ? implode('-', $tables) : 'none')] = [$tables];
        }

        return $sets;
    }

    #[DataProvider('absentTables')]
    public function test_cleanup_works_with_each_combination_of_absent_optional_tables(array $tables): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($tables) {
            foreach ($tables as $table) {
                Schema::drop($table);
            }
        });
        $user = User::factory()->create();
        $old = $this->photo($user);
        $this->upload($user)->assertRedirect('/perfil')->assertSessionHas('success')->assertSessionHasNoErrors();
        $new = $this->assertCommitted($user);
        $this->assertFalse($this->disk->exists($old));
        $this->assertSame([$new], $this->disk->allFiles());
    }

    public function test_a_shared_native_user_reference_still_protects_the_file_without_legacy_schema(): void
    {
        $this->removeLegacySchema();
        $user = User::factory()->create();
        $old = $this->photo($user);
        $other = User::factory()->create(['profile_photo_path' => 'https://example.invalid/storage/'.strtoupper($old).'?v=1#photo']);
        $this->upload($user)->assertSessionHas('success');
        $this->assertCommitted($user);
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
        $this->assertStringContainsString(strtoupper($old), $other->fresh()->profile_photo_path);
    }

    public static function remainingTables(): array
    {
        return [['posts', 'media_path'], ['comments', 'media_path'], ['emotions', 'image_path']];
    }

    #[DataProvider('remainingTables')]
    public function test_a_remaining_legacy_table_still_protects_shared_files(string $table, string $column): void
    {
        $user = User::factory()->create();
        $old = $this->photo($user);
        $reference = 'https://example.invalid/storage/'.strtoupper($old).'?v=1#photo';
        $post = DB::table('posts')->insertGetId(['user_id' => $user->id, 'media_path' => $table === 'posts' ? $reference : null]);
        if ($table === 'comments') {
            DB::table('comments')->insert(['post_id' => $post, 'user_id' => $user->id, $column => $reference, 'deleted_at' => now()]);
        }
        if ($table === 'emotions') {
            DB::table('emotions')->insert(['name' => 'Synthetic', 'slug' => 'synthetic-w62a', $column => $reference]);
        }
        Schema::withoutForeignKeyConstraints(function () use ($table) {
            foreach (['comments', 'posts', 'emotions'] as $other) {
                if ($other !== $table) {
                    Schema::drop($other);
                }
            }
        });
        $this->upload($user)->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertCommitted($user);
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
    }

    public function test_an_unreadable_existing_reference_column_retains_the_previous_file(): void
    {
        $user = User::factory()->create();
        $old = $this->photo($user);
        Schema::table('posts', fn ($table) => $table->dropColumn('media_path'));
        $this->upload($user)->assertSessionHas('success');
        $this->assertCommitted($user);
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
        Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'previous-file-cleanup']);
    }

    public function test_schema_inspection_errors_propagate_instead_of_becoming_absence(): void
    {
        $connection = Mockery::mock(Connection::class)->makePartial();
        $connection->shouldReceive('getPdo')->andReturn(DB::connection()->getPdo());
        $connection->shouldReceive('getSchemaBuilder')->andThrow(new RuntimeException('Synthetic schema failure'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic schema failure');
        app(LegacyPhotoReferences::class)->mayReference($connection, '%synthetic.png%');
    }

    public function test_uncertain_schema_never_triggers_previous_file_deletion(): void
    {
        $user = User::factory()->create();
        $old = $this->photo($user);
        $this->instance(LegacyPhotoReferences::class, Mockery::mock(LegacyPhotoReferences::class, function ($mock) {
            $mock->shouldReceive('mayReference')->once()->andThrow(new RuntimeException('Synthetic metadata outage'));
        }));
        $this->upload($user)->assertSessionHas('success');
        $this->assertCommitted($user);
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
    }

    public function test_schema_probes_and_reference_reads_use_the_writer(): void
    {
        $user = User::factory()->create();
        $connection = $user->getConnection();
        $reader = $connection->getReadPdo();
        $connection->setReadPdo(fn () => throw new RuntimeException('The read replica must not be used'));
        try {
            $references = app(ProfilePhotoReferences::class);
            $this->assertFalse($references->mayReference($user, 'historical/synthetic.png'));
            DB::table('posts')->insert(['user_id' => $user->id, 'media_path' => 'public/historical/synthetic.png']);
            $this->assertTrue($references->mayReference($user, 'historical/synthetic.png'));
        } finally {
            $connection->setReadPdo($reader);
        }
    }

    public function test_sql_failure_compensates_the_new_file_without_any_legacy_table(): void
    {
        $this->removeLegacySchema();
        $user = User::factory()->create();
        $old = $this->photo($user);
        DB::unprepared("CREATE TRIGGER w62a_failure BEFORE UPDATE OF profile_photo_path ON users BEGIN SELECT RAISE(ABORT, 'Synthetic SQL failure'); END");
        $this->upload($user)->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertSame($old, $user->fresh()->profile_photo_path);
        $this->assertSame([$old], $this->disk->allFiles());
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
    }

    public static function storageFaults(): array
    {
        return [['put', false], ['get', null], ['delete', false]];
    }

    #[DataProvider('storageFaults')]
    public function test_storage_failures_remain_safe_without_legacy_schema(string $method, mixed $result): void
    {
        $this->removeLegacySchema();
        $user = User::factory()->create();
        $old = $this->photo($user);
        $disk = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
        Storage::set('public', $disk);
        $fault = $disk->shouldReceive($method)->once();
        if ($method === 'get') {
            $fault->andThrow(new RuntimeException('Synthetic storage read failure'));
        } else {
            $fault->andReturn($result);
        }
        $response = $this->upload($user);
        if ($method === 'delete') {
            $response->assertSessionHas('success');
            $this->assertCommitted($user);
        } else {
            $response->assertSessionHasErrors('photo')->assertSessionMissing('success');
            $this->assertSame($old, $user->fresh()->profile_photo_path);
            $this->assertSame([$old], $this->disk->allFiles());
        }
        $this->assertSame('synthetic-old-photo', $this->disk->get($old));
    }
}
