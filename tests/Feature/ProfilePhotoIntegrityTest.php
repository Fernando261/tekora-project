<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfileController;
use App\Models\Profesionista;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\EnforcedCsrf;
use Tests\Support\IdentityPhotoFixtures;
use Tests\TestCase;

#[Group('photo-integrity-w51')]
class ProfilePhotoIntegrityTest extends TestCase
{
    use IdentityPhotoFixtures;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w51-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
        $this->disk = Storage::fake('public');
        Log::spy();
    }

    private function upload(string $extension = 'png', string $marker = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('replacement.'.$extension, $this->identityPhotoBytes($extension).$marker);
    }

    private function previousPhoto(User $user): string
    {
        $path = 'avatars/avatar_'.$user->id.'_1700000000.png';
        $user->forceFill(['profile_photo_path' => $path])->save();
        $this->disk->put($path, 'synthetic-previous-photo');

        return $path;
    }

    private function faultDisk(): \Mockery\MockInterface
    {
        $proxy = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
        Storage::set('public', $proxy);

        return $proxy;
    }

    public function test_a_false_write_preserves_the_old_reference_and_file_and_reports_photo_error(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->faultDisk()->shouldReceive('put')->once()->andReturn(false);
        $response = $this->actingAs($user, 'web')->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()]);

        $this->assertSame($old, $user->fresh()->profile_photo_path);
        $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
        $this->assertSame([$old], $this->disk->allFiles('avatars'));
        $response->assertRedirect('/perfil')->assertSessionHasErrors('photo')->assertSessionMissing('success');
    }

    private function assertPreviousState(User $user, string $old): void
    {
        $this->assertSame($old, $user->fresh()->profile_photo_path);
        $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
        $this->assertSame([$old], $this->disk->allFiles());
        $this->assertSame(0, $user->getConnection()->transactionLevel());
        $this->assertFalse($user->getConnection()->getPdo()->inTransaction());
    }

    private function assertCurrentPhoto(User $user, string $contents): string
    {
        $path = $user->fresh()->profile_photo_path;
        $this->assertMatchesRegularExpression('#^avatars/avatar_'.$user->id.'_[a-f0-9]{32}\\.png$#', $path);
        $this->assertSame($contents, $this->disk->get($path));

        return $path;
    }

    private function failSqlUpdates(): void
    {
        DB::unprepared("CREATE TRIGGER fail_photo BEFORE UPDATE OF profile_photo_path ON users BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SQL SECRET'); END");
    }

    private function interleavedUpload(User $stale, string $marker): void
    {
        // Real controller/SQLite/file writes at a deterministic request boundary,
        // not a claim of parallel database execution or a second SQL connection.
        $request = Request::create('/perfil/foto', 'POST', [], [], ['photo' => $this->upload('png', $marker)]);
        $request->setUserResolver(fn ($guard = null) => $guard === 'web' ? $stale : null);
        $request->setLaravelSession(session()->driver());
        $response = (new ProfileController)->updatePhoto($request);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_first_photo_is_written_verified_and_committed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])
            ->assertRedirect('/perfil')->assertSessionHas('success', 'Foto de perfil actualizada.')->assertSessionHasNoErrors();
        $path = $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame([$path], $this->disk->allFiles());
    }

    public function test_replacement_keeps_both_files_until_the_new_reference_is_committed(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $order = [];
        $disk = $this->faultDisk();
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use ($user, $old, &$order) {
            $this->assertSame($old, $user->fresh()->profile_photo_path);
            $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
            $order[] = 'write';

            return $this->disk->put($path, $contents);
        });
        $disk->shouldReceive('delete')->once()->with($old)->andReturnUsing(function ($path) use ($user, $old, &$order) {
            $current = $user->fresh()->profile_photo_path;
            $this->assertNotSame($old, $current);
            $this->assertSame($this->identityPhotoBytes(), $this->disk->get($current));
            $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $order[] = 'delete-after-commit';

            return $this->disk->delete($path);
        });
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success');
        $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame(['write', 'delete-after-commit'], $order);
        $this->assertFalse($this->disk->exists($old));
    }

    public static function failedWrites(): array
    {
        return array_map(fn ($mode) => [$mode], ['empty', 'exception', 'partial-false', 'partial-exception', 'truncated-success', 'corrupt-success', 'missing-success']);
    }

    #[DataProvider('failedWrites')]
    public function test_failed_or_incomplete_writes_preserve_old_bytes_and_compensate_only_the_new_file(string $mode): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->faultDisk()->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use ($mode) {
            if (str_starts_with($mode, 'partial') || $mode === 'truncated-success') {
                $this->disk->put($path, substr($contents, 0, 12));
            } elseif ($mode === 'corrupt-success') {
                $this->disk->put($path, str_repeat('x', strlen($contents)));
            }
            if (str_contains($mode, 'exception')) {
                throw new RuntimeException('SYNTHETIC PRIVATE STORAGE SECRET');
            }

            return $mode === 'empty' ? '' : str_ends_with($mode, 'success');
        });
        $response = $this->actingAs($user)->withSession(['success' => 'stale success'])->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()]);
        $response->assertRedirect('/perfil')->assertSessionHasErrors(['photo' => 'No se pudo actualizar la foto. Inténtalo de nuevo.'])->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
        Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'write'])->once();
        $this->assertStringNotContainsString('SECRET', json_encode(session()->get('errors')->getBag('default')->messages()));
    }

    public function test_readback_failure_is_not_reported_as_a_successful_upload(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->faultDisk()->shouldReceive('get')->once()->andThrow(new RuntimeException('SYNTHETIC READ SECRET'));
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public function test_sql_error_rolls_back_the_reference_and_removes_the_verified_new_file(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->failSqlUpdates();
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
        Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'persistence'])->once();
        $this->assertStringNotContainsString('SQL', json_encode(session()->get('errors')->getBag('default')->messages()));
    }

    public function test_a_silently_ignored_sql_update_is_not_mistaken_for_persistence(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        DB::unprepared('CREATE TRIGGER ignore_photo BEFORE UPDATE OF profile_photo_path ON users BEGIN SELECT RAISE(IGNORE); END');
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public function test_a_model_veto_is_a_failed_persistence_and_cleans_the_candidate(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        User::updating(fn () => false);
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public static function failedCommits(): array
    {
        return [['exception'], ['false'], ['false-after-rollback']];
    }

    #[DataProvider('failedCommits')]
    public function test_commit_failure_keeps_the_original_reference_and_file(string $mode): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $connection = $user->getConnection();
        $pdo = $connection->getPdo();
        $proxy = Mockery::mock($pdo);
        if ($mode === 'exception') {
            $proxy->shouldReceive('commit')->once()->andThrow(new PDOException('SYNTHETIC COMMIT SECRET'));
        } elseif ($mode === 'false') {
            $proxy->shouldReceive('commit')->once()->andReturn(false);
        } else {
            $proxy->shouldReceive('commit')->once()->andReturnUsing(function () use ($pdo) {
                $pdo->rollBack();

                return false;
            });
        }
        $connection->setPdo($proxy);
        try {
            $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
            $this->assertPreviousState($user, $old);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->setPdo($pdo);
        }
    }

    public function test_an_exception_after_durable_commit_never_deletes_the_referenced_new_photo(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $connection = $user->getConnection();
        $pdo = $connection->getPdo();
        $proxy = Mockery::mock($pdo);
        $proxy->shouldReceive('commit')->once()->andReturnUsing(function () use ($pdo) {
            $pdo->commit();
            throw new PDOException('SYNTHETIC LOST ACK');
        });
        $connection->setPdo($proxy);
        try {
            $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
            $new = $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
            $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
            $this->assertCount(2, $this->disk->allFiles());
            $this->assertNotSame($old, $new);
        } finally {
            $connection->setPdo($pdo);
        }
    }

    public function test_unconfirmed_rollback_retains_both_files_instead_of_deleting_a_possible_reference(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $connection = $user->getConnection();
        $pdo = $connection->getPdo();
        $proxy = Mockery::mock($pdo);
        $proxy->shouldReceive('commit')->once()->andThrow(new PDOException('SYNTHETIC COMMIT FAILURE'));
        $proxy->shouldReceive('rollBack')->once()->andThrow(new PDOException('SYNTHETIC ROLLBACK FAILURE'));
        $connection->setPdo($proxy);
        try {
            $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
            $this->assertTrue($pdo->inTransaction());
            $this->assertCount(2, $this->disk->allFiles());
            $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
            Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'rollback-unconfirmed'])->once();
        } finally {
            $pdo->rollBack();
            $connection->setPdo($pdo);
        }
        $this->assertSame($old, $user->fresh()->profile_photo_path);
    }

    public static function cleanupFailures(): array
    {
        return [['false'], ['exception']];
    }

    #[DataProvider('cleanupFailures')]
    public function test_old_cleanup_failure_keeps_the_committed_photo_and_records_only_a_safe_phase(string $mode): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $delete = $this->faultDisk()->shouldReceive('delete')->once()->with($old);
        if ($mode === 'false') {
            $delete->andReturn(false);
        } else {
            $delete->andThrow(new RuntimeException('SYNTHETIC PRIVATE DELETE SECRET'));
        }
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success', 'Foto de perfil actualizada.')->assertSessionHasNoErrors();
        $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
        $this->assertCount(2, $this->disk->allFiles());
        Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'previous-file-cleanup'])->once();
    }

    public function test_failed_compensation_is_reported_and_never_removes_the_old_photo(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->failSqlUpdates();
        $this->faultDisk()->shouldReceive('delete')->once()->andReturn(false);
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertSame($old, $user->fresh()->profile_photo_path);
        $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
        $this->assertCount(2, $this->disk->allFiles());
        Log::shouldHaveReceived('warning')->with('Profile photo operation needs attention.', ['phase' => 'new-file-compensation'])->once();
    }

    public function test_two_uploads_in_the_same_second_have_independent_names_and_bytes(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $second = now()->format('Y-m-d H:i:s');
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload('png', 'FIRST')])->assertSessionHas('success');
        $first = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'FIRST');
        $this->post('/perfil/foto', ['photo' => $this->upload('png', 'SECOND')])->assertSessionHas('success');
        $last = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'SECOND');
        $this->assertSame($second, now()->format('Y-m-d H:i:s'));
        $this->assertNotSame($first, $last);
        $this->assertSame([$last], $this->disk->allFiles());
    }

    public function test_stale_authenticated_attributes_cannot_overwrite_sql_or_delete_the_winner(): void
    {
        $user = User::factory()->create();
        $name = $user->name;
        $old = $this->previousPhoto($user);
        $user->name = 'Unsaved stale name';
        $stale = clone $user;
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload('png', 'FIRST')])->assertSessionHas('success');
        $first = $user->fresh()->profile_photo_path;
        $this->assertSame($old, $stale->profile_photo_path);
        $this->actingAs($stale)->post('/perfil/foto', ['photo' => $this->upload('png', 'SECOND')])->assertSessionHas('success');
        $last = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'SECOND');
        $this->assertSame($name, $user->fresh()->name);
        $this->assertFalse($this->disk->exists($first));
        $this->assertSame([$last], $this->disk->allFiles());
    }

    public function test_a_second_upload_can_commit_while_the_first_waits_to_publish_its_file(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $interleaved = false;
        $this->faultDisk()->shouldReceive('put')->twice()->andReturnUsing(function ($path, $contents) use ($user, &$interleaved) {
            $result = $this->disk->put($path, $contents);
            if (! $interleaved) {
                $interleaved = true;
                $this->interleavedUpload(clone $user, 'SECOND');
            }

            return $result;
        });
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload('png', 'FIRST')])->assertSessionHas('success');
        $winner = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'FIRST');
        $this->assertFalse($this->disk->exists($old));
        $this->assertSame([$winner], $this->disk->allFiles());
    }

    public function test_delayed_old_cleanup_cannot_delete_a_later_requests_winning_photo(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $interleaved = false;
        $deleted = [];
        $this->faultDisk()->shouldReceive('delete')->twice()->andReturnUsing(function ($path) use ($user, $old, &$interleaved, &$deleted) {
            if ($path === $old && ! $interleaved) {
                $interleaved = true;
                $this->interleavedUpload(clone $user, 'SECOND');
            }
            $this->assertNotSame($user->fresh()->profile_photo_path, $path);
            $deleted[] = $path;

            return $this->disk->delete($path);
        });
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload('png', 'FIRST')])->assertSessionHas('success');
        $winner = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'SECOND');
        $this->assertSame([$winner], $this->disk->allFiles());
        $this->assertNotContains($winner, $deleted);
    }

    public function test_a_winner_committed_before_confirmation_is_preserved_without_claiming_an_unconfirmed_success(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $interleaved = false;
        app('events')->listen(TransactionCommitted::class, function () use ($user, &$interleaved) {
            if (! $interleaved) {
                $interleaved = true;
                $this->interleavedUpload(clone $user, 'SECOND');
            }
        });
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload('png', 'FIRST')])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $winner = $this->assertCurrentPhoto($user, $this->identityPhotoBytes().'SECOND');
        $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
        $this->assertCount(2, $this->disk->allFiles());
        $this->assertNotSame($old, $winner);
    }

    public function test_guest_upload_is_rejected_before_any_file_write(): void
    {
        $this->from('/perfil')->post('/perfil/foto', ['photo' => $this->upload()])->assertRedirect(route('login'));
        $this->assertSame([], $this->disk->allFiles());
        $this->assertSame(0, User::count());
    }

    public function test_legacy_professional_session_does_not_authorize_a_web_photo_upload(): void
    {
        $professional = Profesionista::create(['nombre' => 'Synthetic legacy', 'email' => 'w51@example.test', 'password' => 'synthetic-password', 'cedula' => 'SYNTHETIC-W51']);
        $before = $professional->fresh()->getAttributes();
        $this->actingAs($professional, 'profesionista')->post('/perfil/foto', ['photo' => $this->upload()])->assertRedirect(route('login'));
        $this->assertSame($before, $professional->fresh()->getAttributes());
        $this->assertSame([], $this->disk->allFiles());
        $this->assertSame(0, User::count());
    }

    public function test_invalid_csrf_never_reaches_storage_or_changes_the_reference(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $this->actingAs($user)->withSession(['_token' => 'synthetic-csrf'])->post('/perfil/foto', ['_token' => 'wrong', 'photo' => $this->upload()])->assertStatus(419);
        $this->assertPreviousState($user, $old);
    }

    public static function invalidImages(): array
    {
        return [['missing'], ['text'], ['large'], ['php-extension']];
    }

    #[DataProvider('invalidImages')]
    public function test_original_image_validation_keeps_reference_and_bytes(string $kind): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $data = match ($kind) {
            'text' => ['photo' => UploadedFile::fake()->createWithContent('file.txt', 'not an image')],
            'large' => ['photo' => UploadedFile::fake()->createWithContent('large.png', $this->identityPhotoBytes().str_repeat('x', 2049 * 1024))],
            'php-extension' => ['photo' => UploadedFile::fake()->createWithContent('image.php', $this->identityPhotoBytes())],
            default => [],
        };
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', $data)->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public function test_profile_keeps_the_native_photo_form_and_public_storage_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success');
        $path = $user->fresh()->profile_photo_path;
        $html = $this->get('/perfil')->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[@enctype="multipart/form-data"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('perfil.updatePhoto'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@type="file" and @name="photo" and @required]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//button[@type="submit"]', $form)->length);
        $this->assertSame(1, $xpath->query('//img[@src="'.Storage::url($path).'"]')->length);
        $this->assertSame(0, $xpath->query('//input[@type="file"]/@onchange | //script[not(@src)]')->length);
        $this->assertSame($this->identityPhotoBytes(), $this->disk->get($path));
    }

    public function test_another_users_current_or_legacy_shared_file_is_never_deleted(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $path = 'historical/shared-photo.png';
        $this->disk->put($path, 'synthetic-shared-photo');
        $user->forceFill(['profile_photo_path' => $path])->save();
        $other->forceFill(['profile_photo_path' => 'https://historical.example.invalid/storage/'.strtoupper($path).'?v=legacy#photo'])->save();
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success');
        $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame('synthetic-shared-photo', $this->disk->get($path));
        $this->assertSame('https://historical.example.invalid/storage/'.strtoupper($path).'?v=legacy#photo', $other->fresh()->profile_photo_path);
    }

    public static function unsafeReferences(): array
    {
        return [['avatars/avatar_999_1700000000.png', 'avatars/avatar_999_1700000000.png'],
            ['uploads/posts/images/keep.png', 'uploads/posts/images/keep.png'],
            ['emotions/keep.png', 'emotions/keep.png'],
            ['avatars/../keep.png', 'keep.png'],
            ['../keep.png', 'keep.png'],
            ['/keep.png', 'keep.png'],
            ['https://example.invalid/keep.png', 'keep.png']];
    }

    #[DataProvider('unsafeReferences')]
    public function test_unmanaged_other_owner_or_manipulated_paths_are_not_deleted(string $reference, string $sentinel): void
    {
        $user = User::factory()->create();
        $user->forceFill(['profile_photo_path' => $reference])->save();
        $this->disk->put($sentinel, 'synthetic-other-module');
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success');
        $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame('synthetic-other-module', $this->disk->get($sentinel));
        $this->assertCount(2, $this->disk->allFiles());
    }

    public static function mediaReferences(): array
    {
        return [['posts', 'media_path'], ['comments', 'media_path'], ['emotions', 'image_path']];
    }

    #[DataProvider('mediaReferences')]
    public function test_other_modules_references_prevent_legacy_photo_deletion(string $table, string $column): void
    {
        $user = User::factory()->create();
        $path = 'historical/shared-with-module.png';
        $user->forceFill(['profile_photo_path' => $path])->save();
        $this->disk->put($path, 'synthetic-module-media');
        $post = DB::table('posts')->insertGetId(['user_id' => $user->id, 'content' => 'Synthetic post']);
        $id = match ($table) {
            'posts' => $post,
            'comments' => DB::table('comments')->insertGetId(['user_id' => $user->id, 'post_id' => $post, 'content' => 'Synthetic comment']),
            'emotions' => DB::table('emotions')->insertGetId(['name' => 'Synthetic', 'slug' => 'w51-synthetic', 'image_path' => $path]),
        };
        DB::table($table)->where('id', $id)->update([$column => 'public/'.$path]);
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHas('success');
        $this->assertCurrentPhoto($user, $this->identityPhotoBytes());
        $this->assertSame('synthetic-module-media', $this->disk->get($path));
        $this->assertSame('public/'.$path, DB::table($table)->where('id', $id)->value($column));
    }

    public function test_an_existing_random_target_is_not_overwritten_or_compensated(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $proxy = $this->faultDisk();
        $proxy->shouldReceive('exists')->once()->andReturn(true);
        $proxy->shouldNotReceive('put');
        $proxy->shouldNotReceive('delete');
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public function test_local_target_resolving_outside_the_public_root_is_rejected_before_writing(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $proxy = $this->faultDisk();
        $proxy->shouldReceive('path')->andReturnUsing(fn ($path) => $path === '' ? $this->disk->path('') : dirname($this->disk->path('')));
        $proxy->shouldNotReceive('put');
        $proxy->shouldNotReceive('delete');
        $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
        $this->assertPreviousState($user, $old);
    }

    public function test_an_outer_transaction_is_not_mistaken_for_a_durable_photo_commit(): void
    {
        $user = User::factory()->create();
        $old = $this->previousPhoto($user);
        $connection = $user->getConnection();
        $connection->beginTransaction();
        try {
            $this->actingAs($user)->post('/perfil/foto', ['photo' => $this->upload()])->assertSessionHasErrors('photo')->assertSessionMissing('success');
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertTrue($connection->getPdo()->inTransaction());
            $this->assertSame($old, $user->fresh()->profile_photo_path);
            $this->assertSame('synthetic-previous-photo', $this->disk->get($old));
            $this->assertSame([$old], $this->disk->allFiles());
        } finally {
            $connection->rollBack();
        }
    }
}
