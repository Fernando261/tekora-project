<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\Role;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

#[Group('web-w5')]
class TekoraW5Test extends TestCase
{
    use CatalogFixtures;
    use \Tests\Support\IdentityPhotoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $hot = tempnam(sys_get_temp_dir(), 'tekora-w5-hot-');
        if (is_file(public_path('build/manifest.json'))) {
            unlink($hot);
        } else {
            file_put_contents($hot, 'http://127.0.0.1:5173');
        }
        app(\Illuminate\Foundation\Vite::class)->useHotFile($hot);
        $this->beforeApplicationDestroyed(function () use ($hot) {
            if (is_file($hot)) {
                unlink($hot);
            }
        });
    }

    private function document(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function fakePhotos(): void
    {
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w5-files-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        Storage::fake('public');
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
    }

    public static function actors(): array
    {
        return [['guest'], ['none'], ['client'], ['provider'], ['dual'], ['admin'], ['legacy']];
    }

    #[DataProvider('actors')]
    public function test_account_center_preserves_each_role_and_never_enrolls_on_get(string $actor): void
    {
        if ($actor === 'legacy') {
            $professional = Profesionista::create(['nombre' => 'Identidad anterior', 'email' => 'legacy-w5@example.test', 'password' => Hash::make('legacy-password'), 'cedula' => 'W5-123']);
            $this->actingAs($professional, 'profesionista');
        } elseif ($actor !== 'guest') {
            $roles = match ($actor) {
                'client' => [RoleName::Client], 'provider' => [RoleName::Provider], 'dual' => [RoleName::Client, RoleName::Provider], 'admin' => [RoleName::Admin], default => []
            };
            $user = $this->account(...$roles);
            $this->actingAs($user, 'web');
        }
        $before = DB::table('role_user')->get()->toArray();
        $response = $this->get('/cuenta');
        if (in_array($actor, ['guest', 'legacy'])) {
            $response->assertRedirect(route('login'));
        } else {
            $response->assertOk()->assertSee('Hola, '.$user->name)->assertSee('tk-identity')->assertSee('Quiero contratar')->assertSee('Mis datos personales');
            $xpath = $this->document($response->getContent());
            $this->assertSame($actor === 'admin' ? 1 : 0, $xpath->query('//main//a[@href="'.route('identity.roles.index').'"]')->length);
            $this->assertSame(in_array($actor, ['client', 'dual']) ? 0 : 1, $xpath->query('//main//form//input[@name="role" and @value="client"]')->length);
            $this->assertSame(in_array($actor, ['provider', 'dual']) ? 0 : 1, $xpath->query('//main//a[@href="'.route('account.activate-provider').'"]')->length);
            $this->assertSame(0, $xpath->query('//main//input[@value="admin"]')->length);
            $this->assertStringContainsString("script-src 'self'", $xpath->query('//meta[@http-equiv="Content-Security-Policy"]/@content')->item(0)->nodeValue);
        }
        $this->assertEquals($before, DB::table('role_user')->get()->toArray());
    }

    public function test_activation_uses_rendered_csrf_and_is_idempotent_without_creating_a_second_identity(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $user = $this->account(RoleName::Client);
        $this->actingAs($user)->withSession(['_token' => 'w5-csrf']);
        $html = $this->get('/cuenta/activar-prestador')->assertOk()->assertSee('no es una verificación profesional')->assertSee('ni fusiona cuentas', false)->getContent();
        $xpath = $this->document($html);
        $this->assertSame('provider', $xpath->query('//main//input[@name="role"]/@value')->item(0)->nodeValue);
        $this->post('/cuenta/roles', ['role' => 'provider'])->assertStatus(419);
        $this->post('/cuenta/roles', ['role' => 'provider', '_token' => 'w5-csrf'])->assertRedirect(route('account.provider'));
        $this->post('/cuenta/roles', ['role' => 'provider', '_token' => 'w5-csrf'])->assertStatus(303);
        $this->assertSame(['client', 'provider'], $user->roles()->orderBy('slug')->pluck('slug')->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->get('/cuenta/activar-prestador')->assertOk()->assertSee('Ya está activado')->assertDontSee('name="role"', false);
        $this->postJson('/cuenta/roles', ['role' => 'admin', '_token' => 'w5-csrf'])->assertUnprocessable();
        $this->assertFalse($user->hasRole(RoleName::Admin));
    }

    public function test_administration_is_read_only_and_does_not_disclose_users_or_add_editor_forms(): void
    {
        $admin = $this->account(RoleName::Admin);
        $other = $this->account(RoleName::Client);
        $this->actingAs($other)->get('/administracion/roles')->assertForbidden();
        $response = $this->actingAs($admin)->get('/administracion/roles')->assertOk()->assertSee('Esta pantalla es de consulta')->assertDontSee($other->email);
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//main//form | //main//button | //main//input')->length);
        foreach (Role::pluck('name') as $name) {
            $response->assertSee($name);
        }
        $this->assertSame(3, $xpath->query('//ul[contains(@class,"tk-identity-roles")]/li')->length);
    }

    public function test_personal_data_is_private_web_identity_and_never_added_to_decorative_attributes(): void
    {
        $web = $this->account(RoleName::Client);
        $web->update(['name' => 'Nombre web privado', 'age' => 37, 'gender' => 'Dato privado']);
        $legacy = Profesionista::create(['nombre' => 'Nombre histórico privado', 'email' => $web->email, 'password' => Hash::make('legacy-password'), 'cedula' => 'W5-456']);
        $this->actingAs($legacy, 'profesionista')->actingAs($web, 'web');
        $response = $this->get('/perfil')->assertOk()->assertSee($web->name)->assertSee($web->email)->assertSee('Dato privado')->assertDontSee($legacy->nombre);
        $xpath = $this->document($response->getContent());
        foreach ($xpath->query('//*[@data-identity-scene] | //*[@data-identity-scene]//*') as $node) {
            $this->assertStringNotContainsString($web->email, $node->textContent);
            foreach ($node->attributes as $attribute) {
                $this->assertStringNotContainsString($web->email, $attribute->value);
            }
        }
        $this->assertSame(0, $xpath->query('//script[not(@src)] | //@onchange | //@onclick')->length);
        $form = $xpath->query('//form[@enctype="multipart/form-data"]')->item(0);
        $this->assertSame(route('perfil.updatePhoto'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@type="file" and @name="photo"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//button[@type="submit"]', $form)->length);
        auth('web')->logout();
        $this->get('/perfil')->assertRedirect(route('login'))->assertDontSee($web->email);
    }

    public function test_name_form_keeps_old_escaped_input_label_limit_put_and_csrf(): void
    {
        $user = $this->account(RoleName::Client);
        $this->actingAs($user);
        $text = '<script>W5-XSS</script> Nombre';
        $response = $this->withSession(['_old_input' => ['name' => $text]])->get('/perfil/editar-nombre')->assertOk()->assertDontSee('<script>W5-XSS', false);
        $xpath = $this->document($response->getContent());
        $this->assertSame($text, $xpath->query('//input[@name="name"]/@value')->item(0)->nodeValue);
        $this->assertSame('255', $xpath->query('//input[@name="name"]/@maxlength')->item(0)->nodeValue);
        $this->assertSame('PUT', $xpath->query('//input[@name="_method"]/@value')->item(0)->nodeValue);
        $this->put('/perfil/actualizar-nombre', ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->from('/perfil/editar-nombre')->put('/perfil/actualizar-nombre', ['name' => ['bad']])->assertSessionHasErrors('name');
        $this->get('/perfil/editar-nombre')->assertOk();
        $this->put('/perfil/actualizar-nombre', ['name' => 'Nombre actualizado'])->assertRedirect(route('perfil'))->assertSessionHas('success');
        $this->assertSame('Nombre actualizado', $user->fresh()->name);
        $this->assertDatabaseCount('users', 1);
    }

    public static function imageFormats(): array
    {
        return [['jpg'], ['jpeg'], ['png'], ['webp']];
    }

    #[DataProvider('imageFormats')]
    public function test_photo_upload_keeps_public_disk_path_redirect_and_only_changes_fake_files(string $extension): void
    {
        $this->fakePhotos();
        $user = $this->account(RoleName::Client);
        $user->forceFill(['profile_photo_path' => 'historical/previous.png'])->save();
        Storage::disk('public')->put('historical/previous.png', 'synthetic-old-image');
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', ['photo' => UploadedFile::fake()->createWithContent('avatar.'.$extension, $this->identityPhotoBytes($extension))])->assertRedirect('/perfil')->assertSessionHas('success', 'Foto de perfil actualizada.');
        $path = $user->fresh()->profile_photo_path;
        $this->assertStringStartsWith('avatars/avatar_'.$user->id.'_', $path);
        $this->assertStringEndsWith('.'.$extension, $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing('historical/previous.png');
        $html = $this->get('/perfil')->assertOk()->getContent();
        $this->assertSame(1, $this->document($html)->query('//img[@src="'.Storage::url($path).'"]')->length);
        $this->assertStringContainsString(sys_get_temp_dir(), Storage::disk('public')->path($path));
    }

    public static function invalidPhotos(): array
    {
        return [['text'], ['large'], ['missing']];
    }

    #[DataProvider('invalidPhotos')]
    public function test_invalid_photo_keeps_the_existing_reference_and_fake_file(string $kind): void
    {
        $this->fakePhotos();
        $user = $this->account(RoleName::Client);
        $user->forceFill(['profile_photo_path' => 'historical/keep.png'])->save();
        Storage::disk('public')->put('historical/keep.png', 'synthetic-image');
        $data = match ($kind) {
            'text' => ['photo' => UploadedFile::fake()->create('avatar.txt', 1, 'text/plain')],'large' => ['photo' => UploadedFile::fake()->createWithContent('avatar.png', $this->identityPhotoBytes().str_repeat('x', 2049 * 1024))],default => []
        };
        $this->actingAs($user)->from('/perfil')->post('/perfil/foto', $data)->assertSessionHasErrors('photo');
        $this->assertSame('historical/keep.png', $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists('historical/keep.png');
        $this->get('/perfil')->assertOk()->assertSee('2048 KB');
    }

    public function test_existing_photo_reference_is_read_without_moving_deleting_or_uploading_anything(): void
    {
        $this->fakePhotos();
        $user = $this->account(RoleName::Client);
        $user->forceFill(['profile_photo_path' => 'historical/existing-photo.png'])->save();
        Storage::disk('public')->put($user->profile_photo_path, 'unchanged-synthetic-media');
        $before = Storage::disk('public')->get($user->profile_photo_path);
        $this->actingAs($user)->get('/perfil')->assertOk()->assertSee(Storage::url($user->profile_photo_path), false);
        $this->assertSame($before, Storage::disk('public')->get($user->profile_photo_path));
        $this->assertSame('historical/existing-photo.png', $user->fresh()->profile_photo_path);
    }

    public function test_real_recovery_notification_is_faked_and_valid_token_resets_only_the_web_password(): void
    {
        Notification::fake();
        $user = $this->account(RoleName::Client);
        $original = $user->password;
        $legacy = Profesionista::create(['nombre' => 'Cuenta histórica separada', 'email' => $user->email, 'password' => Hash::make('legacy-unchanged'), 'cedula' => 'W5-RESET']);
        $legacyHash = $legacy->password;
        $this->from('/forgot-password')->post(route('password.email'), ['email' => $user->email])->assertRedirect('/forgot-password')->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $response = $this->get(route('password.reset', $token))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame($token, $xpath->query('//input[@name="token"]/@value')->item(0)->nodeValue);
        foreach ($xpath->query('//script/@src | //link/@href | //*[@data-identity-scene]//@*') as $attribute) {
            $this->assertStringNotContainsString($token, $attribute->nodeValue);
        }
        foreach ($xpath->query('//input[@type="password"]') as $field) {
            $this->assertFalse($field->hasAttribute('value'));
            $this->assertSame('new-password', $field->getAttribute('autocomplete'));
        }
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-test-password', 'password_confirmation' => 'new-test-password'])->assertRedirect(route('login'))->assertSessionHas('status', 'Contraseña actualizada correctamente');
        $this->assertNotSame($original, $user->fresh()->password);
        $this->assertTrue(Hash::check('new-test-password', $user->fresh()->password));
        $this->assertGuest('web');
        $this->assertSame($legacyHash, $legacy->fresh()->password);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_expired_and_unconfirmed_tokens_preserve_hash_and_real_errors(): void
    {
        $user = $this->account(RoleName::Client);
        $hash = $user->password;
        $token = Password::broker()->createToken($user);
        $this->from(route('password.reset', $token))->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'valid-password', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['token' => 'invalid-synthetic', 'email' => $user->email, 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])->assertSessionHasErrors('email');
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])->assertSessionHasErrors('email');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertGuest('web');
    }

    public function test_recovery_forms_preserve_old_email_and_never_refill_passwords_or_echo_array_inputs(): void
    {
        $this->withSession(['_old_input' => ['email' => '<script>W5-XSS</script>', 'password' => 'private-password', 'password_confirmation' => 'private-password']]);
        foreach (['/forgot-password', '/reset-password/synthetic-w5-token'] as $uri) {
            $response = $this->get($uri)->assertOk()->assertDontSee('MindBloom')->assertDontSee('private-password')->assertDontSee('<script>W5-XSS', false);
            $x = $this->document($response->getContent());
            $this->assertSame(1, $x->query('//form[@method="POST"]//input[@name="_token"]')->length);
            $this->assertSame(0, $x->query('//script[not(@src)]')->length);
        }
        $this->withSession(['_old_input' => ['email' => ['bad']]])->get('/forgot-password')->assertOk();
        $this->get('/reset-password/synthetic-w5-token')->assertOk();
    }

    public static function failures(): array
    {
        return [['account.client-error'], ['provider-dashboard.error'], ['errors.419']];
    }

    #[DataProvider('failures')]
    public function test_fallbacks_are_standalone_and_do_not_query_navigation_or_leak_internals(string $view): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $html = view($view, ['message' => 'Datos temporalmente no disponibles.'])->render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        $this->assertStringContainsString('tk-identity-fallback', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//main//h1')->length);
        $this->assertSame(0, $xpath->query('//aside | //form')->length);
    }

    public function test_name_photo_and_recovery_reject_missing_csrf_and_preserve_419_semantics(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->fakePhotos();
        $user = $this->account(RoleName::Client);
        $this->actingAs($user);
        $this->put('/perfil/actualizar-nombre', ['name' => 'No guardar'])->assertStatus(419)->assertSee('Tu sesión caducó');
        $this->post('/perfil/foto', ['photo' => UploadedFile::fake()->createWithContent('fake.png', $this->identityPhotoBytes())])->assertStatus(419);
        $this->post(route('password.email'), ['email' => $user->email])->assertStatus(419);
        $this->post(route('password.update'), ['token' => 'synthetic', 'email' => $user->email, 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertStatus(419);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public static function assetModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('assetModes')]
    public function test_identity_assets_reference_actual_js_and_css_with_a_manifest_or_without_a_build(bool $build): void
    {
        $original = public_path();
        $temporary = sys_get_temp_dir().'/tekora-w5-vite-'.bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        $hot = $temporary.'/hot';
        $vite = app(\Illuminate\Foundation\Vite::class);
        try {
            if ($build && ! is_file($original.'/build/manifest.json')) {
                $this->markTestSkipped('Run npm ci and npm run build for the manifest case.');
            }
            if (! $build) {
                app()->usePublicPath($temporary);
                file_put_contents($hot, 'http://127.0.0.1:5173');
            }
            $vite->useHotFile($hot);
            $this->actingAs($this->account(RoleName::Client));
            $x = $this->document($this->get('/cuenta')->assertOk()->getContent());
            $manifest = $build ? json_decode(file_get_contents($original.'/build/manifest.json'), true) : [];
            foreach (['css', 'js'] as $type) {
                $entry = 'resources/'.$type.'/tekora/identity.'.$type;
                $path = $build ? 'build/'.$manifest[$entry]['file'] : $entry;
                $selector = $type === 'js' ? '//script[@type="module"]/@src' : '//link[@rel="stylesheet"]/@href';
                $urls = array_map(fn ($n) => $n->nodeValue, iterator_to_array($x->query($selector)));
                $this->assertCount(1, array_filter($urls, fn ($url) => str_ends_with(parse_url($url, PHP_URL_PATH), '/'.$path)));
            }
        } finally {
            app()->usePublicPath($original);
            $vite->useHotFile($original.'/hot');
            if (is_file($hot)) {
                unlink($hot);
            }rmdir($temporary);
        }
    }

    public function test_identity_is_opt_in_and_keeps_the_api_migrations_legacy_and_gets_read_only(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $this->service($owner);
        $this->actingAs($owner);
        foreach (['/', '/login', '/cuenta/cliente', '/cuenta/prestador/dashboard', '/servicios', '/prestadores', '/cuenta/servicios', '/cuenta/prestador', '/mindbloom/inicio'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertSame(0, $this->document($html)->query('//body[contains(concat(" ",@class," ")," tk-identity ")]')->length);
            $this->assertStringNotContainsString('/assets/identity-', $html);
        }
        $before = DB::table('role_user')->get()->toArray();
        foreach (['/cuenta', '/cuenta/activar-prestador', '/perfil', '/perfil/editar-nombre'] as $uri) {
            $this->get($uri)->assertOk();
        }$this->assertEquals($before, DB::table('role_user')->get()->toArray());
        $this->assertCount(37, glob(database_path('migrations/*.php')));
        $this->assertCount(21, array_filter(app('router')->getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/')));
        $this->assertContains('auth:profesionista', app('router')->getRoutes()->getByName('profesionista.dashboard')->gatherMiddleware());
    }
}
