<?php

// Existing guarded W4 export stays unchanged. No persistent env, DB or browser POST.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$originParts = parse_url($argv[2] ?? '');
if (($originParts['scheme'] ?? '') !== 'http' || ($originParts['host'] ?? '') !== '127.0.0.1') {
    fwrite(STDERR, "Use a NEW directory and an explicit http://127.0.0.1:PORT origin.\n");
    exit(1);
}
require __DIR__.'/tekora-w4-preview.php';

final class TekoraW5Export extends \Tests\TestCase
{
    use \Tests\Support\CatalogFixtures;
    use \Tests\Support\IdentityPhotoFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-w5-absent-'.bin2hex(random_bytes(16)));
        if (is_file($app->environmentFilePath())) {
            throw new RuntimeException('Environment file must be absent.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function exportIdentity(string $target, string $origin): void
    {
        $this->setUp();
        $this->app['url']->forceRootUrl($origin);
        $this->app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-w5-no-hot-'.bin2hex(random_bytes(8)));
        $storage = sys_get_temp_dir().'/tekora-w5-photos-'.bin2hex(random_bytes(8));
        $oldStorage = storage_path();
        $this->app->useStoragePath($storage);
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Notification::fake();
        $routes = json_decode(file_get_contents($target.'/routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $statuses = json_decode(file_get_contents($target.'/status-codes.json'), true, flags: JSON_THROW_ON_ERROR);
        $pages = [];
        $queries = [];
        $save = function (string $uri, string $name, string $actor, int $status = 200) use ($target, &$routes, &$statuses, &$pages, &$queries) {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $response = $this->get($uri);
            if ($response->getStatusCode() !== $status) {
                throw new RuntimeException($name.' returned '.$response->getStatusCode().': '.($response->exception?->getMessage() ?? 'Unexpected status.'));
            }
            $log = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $file = 'identity-'.$name.'.html';
            file_put_contents($target.'/'.$file, $response->getContent());
            $key = tekoraMarketplacePreviewKey($uri);
            $alias = '/preview/w5/'.$name;
            $routes[$key] = $routes[$alias] = $file;
            $statuses[$key] = $statuses[$alias] = $status;
            // Only synthetic fixture paths are recorded; no real recovery tokens or credentials.
            $pages[$name] = ['path' => $alias, 'original_uri' => $uri, 'role' => $actor, 'status' => $status, 'source' => 'Real controller GET over synthetic memory fixtures'];
            $queries[$name] = ['count' => count($log), 'writes' => count(array_filter($log, fn ($q) => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q['query'])))];
        };
        try {
            $actors = [];
            foreach (['client' => [\App\Enums\RoleName::Client], 'provider' => [\App\Enums\RoleName::Provider], 'dual' => [\App\Enums\RoleName::Client, \App\Enums\RoleName::Provider], 'admin' => [\App\Enums\RoleName::Admin], 'none' => []] as $name => $roles) {
                $actors[$name] = $this->account(...$roles);
                $actors[$name]->update(['name' => 'Ariadna · Demostración', 'email' => 'cuenta-'.$name.'@example.test', 'age' => 32, 'gender' => 'Prefiero no decirlo']);
                $this->actingAs($actors[$name], 'web');
                $save('/cuenta', 'account-'.$name, $name);
            }
            $this->actingAs($actors['client'], 'web');
            $save('/cuenta/activar-prestador', 'activation-pending', 'client');
            $save('/perfil', 'profile-no-photo', 'client');
            $save('/perfil/editar-nombre', 'edit-name', 'client');
            $this->withSession(['_old_input' => ['name' => 'Mi nombre introducido · Demostración'], 'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['name' => 'Revisa el nombre antes de guardar.']))]);
            $save('/perfil/editar-nombre', 'name-validation', 'client');
            session()->flush();
            // A recognizable artificial image; no existing media is read or copied.
            $png = $this->identityPhotoBytes();
            \Illuminate\Support\Facades\Storage::disk('public')->put('avatars/w5-fixture.png', $png);
            $actors['client']->forceFill(['profile_photo_path' => 'avatars/w5-fixture.png'])->save();
            $this->actingAs($actors['client']->fresh(), 'web');
            $save('/perfil', 'profile-photo', 'client');
            mkdir($target.'/fixtures', 0700);
            file_put_contents($target.'/fixtures/avatar.png', $png);
            $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['photo' => 'Selecciona una imagen válida de hasta 2048 KB.']))]);
            $save('/perfil', 'photo-validation', 'client');
            session()->flush();
            $this->actingAs($actors['provider'], 'web');
            $save('/cuenta/activar-prestador', 'activation-active', 'provider');
            $this->actingAs($actors['admin'], 'web');
            $save('/administracion/roles', 'roles', 'admin');
            $this->app['auth']->guard('web')->logout();
            $save('/forgot-password', 'forgot', 'guest');
            $this->withSession(['status' => 'Hemos enviado el enlace de recuperación a tu correo electrónico.']);
            $save('/forgot-password', 'forgot-sent', 'guest · synthetic status only');
            session()->flush();
            $this->withSession(['_old_input' => ['email' => 'direccion-invalida'], 'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => 'Introduce una dirección de correo válida.']))]);
            $save('/forgot-password', 'forgot-validation', 'guest');
            session()->flush();
            $save('/reset-password/W5-SYNTHETIC-NONFUNCTIONAL-TOKEN', 'reset', 'guest');
            $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => 'El enlace de recuperación no es válido o ha caducado.']))]);
            $save('/reset-password/W5-SYNTHETIC-NONFUNCTIONAL-TOKEN', 'reset-invalid', 'guest');
            session()->flush();
            // POST-only error presentations: deliberately not represented as executed operations.
            foreach (['account.client-error' => 'client-error', 'provider-dashboard.error' => 'provider-error', 'errors.419' => 'session-expired'] as $view => $name) {
                \Illuminate\Support\Facades\DB::enableQueryLog();
                \Illuminate\Support\Facades\DB::flushQueryLog();
                $html = view($view, ['message' => 'Los datos no están disponibles temporalmente. Reintenta más tarde.'])->render();
                $log = \Illuminate\Support\Facades\DB::getQueryLog();
                \Illuminate\Support\Facades\DB::disableQueryLog();
                $file = 'identity-'.$name.'.html';
                file_put_contents($target.'/'.$file, $html);
                $alias = '/preview/w5/'.$name;
                $routes[$alias] = $file;
                $statuses[$alias] = $name === 'session-expired' ? 419 : 503;
                $pages[$name] = ['path' => $alias, 'role' => 'fallback', 'status' => $statuses[$alias], 'source' => 'Existing fallback Blade with synthetic message; no failed operation simulated'];
                $queries[$name] = ['count' => count($log), 'writes' => 0];
            }
            $linked = [];
            $this->actingAs($actors['client']->fresh(), 'web');
            $save('/cuenta/cliente', 'linked-client-dashboard', 'client');
            $linked['client-dashboard'] = $pages['linked-client-dashboard'];
            unset($pages['linked-client-dashboard']);
            $this->actingAs($actors['provider'], 'web');
            foreach (['/cuenta/prestador' => 'provider-form', '/cuenta/prestador/dashboard' => 'provider-dashboard'] as $uri => $name) {
                $save($uri, 'linked-'.$name, 'provider');
                $linked[$name] = $pages['linked-'.$name];
                unset($pages['linked-'.$name]);
            }
            $this->app['auth']->guard('web')->logout();
            $guest = $this->get('/cuenta');
            if ($guest->getStatusCode() !== 302) {
                throw new RuntimeException('Guest must redirect to login.');
            }
            $redirects = json_decode(file_get_contents($target.'/redirects.json'), true);
            $redirects['/preview/w5/account-guest'] = '/login';
            file_put_contents($target.'/redirects.json', json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($target.'/w5-linked-pages.json', json_encode($linked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            // Restore canonical routes to explicit coherent snapshots after recording role/session states.
            foreach (['account-client', 'activation-pending', 'profile-photo', 'edit-name', 'roles', 'forgot', 'reset'] as $name) {
                $routes[tekoraMarketplacePreviewKey($pages[$name]['original_uri'])] = 'identity-'.$name.'.html';
            }
            foreach (['routes' => $routes, 'status-codes' => $statuses, 'w5-pages' => $pages, 'w5-queries' => $queries] as $name => $data) {
                file_put_contents($target.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            file_put_contents($target.'/w5-safety.json', json_encode(['environment' => $this->app->environment(), 'connection' => config('database.default'), 'database' => config('database.connections.sqlite.database'), 'environment_file_exists' => is_file($this->app->environmentFilePath()), 'post_backend' => false, 'mail_backend' => false, 'storage_fake' => true, 'real_media_copied' => false, 'token' => 'Synthetic nonfunctional fixture only'], JSON_PRETTY_PRINT));
            echo 'W5 export: '.count($pages)." pages; memory DB, fake photos and notifications, no browser POST.\n";
        } finally {
            $this->app->useStoragePath($oldStorage);
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($storage);
            \Illuminate\Support\Facades\DB::disconnect();
        }
    }
}
try {
    (new TekoraW5Export('exportIdentity'))->exportIdentity($target, $origin);
} catch (\Throwable $e) {
    fwrite(STDERR, 'W5 export failed: '.$e->getMessage()."\n");
    exit(1);
}
