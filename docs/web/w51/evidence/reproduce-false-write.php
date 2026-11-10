<?php

// Standalone diagnostic: only memory DB/fake disk; never a web endpoint.
if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['before', 'after'], true)) {
    http_response_code(404);
    exit(1);
}
$mode = $argv[1];
$root = dirname(__DIR__, 4);
require $root.'/tests/bootstrap.php';
if ($mode === 'before') {
    $fixture = __DIR__.'/approved-controller.php.txt';
    if (hash_file('sha256', $fixture) !== 'd5ab0e49f8047f3b5d599cef22e5eb1d1bbbb355da64ebbace6f8598d5beb084') {
        throw new RuntimeException('The approved controller fixture changed.');
    }
    // Load the exact approved class before Composer resolves the production class.
    require $fixture;
}

final class W51FalseWriteDiagnostic extends \Tests\TestCase
{
    use \Tests\Support\IdentityPhotoFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-w51-absent-'.bin2hex(random_bytes(8)));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function reproduce(string $mode): void
    {
        $this->setUp();
        $originalStorage = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w51-proof-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        try {
            $disk = \Illuminate\Support\Facades\Storage::fake('public');
            \Illuminate\Support\Facades\Log::spy();
            $user = \App\Models\User::factory()->create();
            $old = 'avatars/avatar_'.$user->id.'_1700000000.png';
            $user->forceFill(['profile_photo_path' => $old])->save();
            $disk->put($old, 'synthetic-previous-photo');
            $proxy = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
            $proxy->shouldReceive('put')->once()->andReturn(false);
            \Illuminate\Support\Facades\Storage::set('public', $proxy);
            $response = $this->actingAs($user, 'web')->from('/perfil')->post('/perfil/foto', ['photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('replacement.png', $this->identityPhotoBytes())]);
            $path = $user->fresh()->profile_photo_path;
            $result = [
                'mode' => $mode,
                'connection' => config('database.default'),
                'database' => config('database.connections.sqlite.database'),
                'environment_file_exists' => is_file(app()->environmentFilePath()),
                'fake_disk' => true,
                'status' => $response->getStatusCode(),
                'success_reported' => session('success') === 'Foto de perfil actualizada.',
                'photo_error' => session('errors')?->getBag('default')->has('photo') ?? false,
                'previous_reference_preserved' => $path === $old,
                'previous_file_exists' => $disk->exists($old),
                'previous_bytes_preserved' => $disk->exists($old) && $disk->get($old) === 'synthetic-previous-photo',
                'referenced_file_exists' => $disk->exists($path),
                'fake_files' => count($disk->allFiles()),
            ];
            $this->assertSame('testing', app()->environment());
            $this->assertFalse($result['environment_file_exists']);
            $this->assertSame($mode === 'after', $result['previous_reference_preserved']);
            $this->assertSame($mode === 'after', $result['previous_bytes_preserved']);
            $this->assertSame($mode === 'before', $result['success_reported']);
            $this->assertSame($mode === 'after', $result['photo_error']);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        } finally {
            app()->useStoragePath($originalStorage);
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($temporary);
            \Illuminate\Support\Facades\DB::disconnect();
            \Mockery::close();
        }
    }
}

(new W51FalseWriteDiagnostic('reproduce'))->reproduce($mode);
