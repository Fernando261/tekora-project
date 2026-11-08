<?php

// CLI-only fixture export. A real controller read over SQLite :memory:, never a live application.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$target = $argv[1] ?? null;
$origin = $argv[2] ?? 'http://127.0.0.1:8016';
$url = parse_url($origin);
if (! $target || file_exists($target) || ! in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    || ($url['scheme'] ?? '') !== 'http' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
    fwrite(STDERR, "Use a NEW directory and a loopback origin: php tools/tekora-w2-preview.php <TEMP-directory> http://127.0.0.1:8016\n");
    exit(1);
}
require dirname(__DIR__).'/tests/bootstrap.php';

final class TekoraW2VisualExport extends \Tests\TestCase
{
    use \Tests\Support\DashboardFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        // Never let Dotenv read this checkout's persistent .env, even for optional settings.
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-preview-no-env-'.bin2hex(random_bytes(16)));
        if (is_file($app->environmentFilePath())) {
            throw new RuntimeException('Preview requires an absent environment file.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function export(string $target, string $origin): void
    {
        $this->setUp(); // Existing bootstrap/TestCase fail before migrating unless testing + SQLite :memory:.
        $this->app['url']->forceRootUrl($origin);
        $this->app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-w2-no-hot-'.bin2hex(random_bytes(8)));
        mkdir($target, 0700, true);
        $routes = [];
        $save = function (string $uri, string $file, ?int $status = 200) use ($target, &$routes) {
            $response = $this->get($uri);
            if ($response->getStatusCode() !== $status) {
                throw new RuntimeException('Visual export failed for '.$uri);
            }
            file_put_contents($target.'/'.$file.'.html', $response->getContent());
            $routes[$uri] = $file.'.html';
        };
        [$client, $actor, $inquiry] = $this->reservationContext();
        $actor->update(['name' => 'Alex · Demostración', 'email' => 'alex-w2@example.test']);
        $actor->providerProfile->update(['display_name' => 'Estudio Nodo', 'headline' => 'Diseño, desarrollo y nuevas posibilidades']);
        $workflow = app(\App\Services\ReservationWorkflow::class);
        foreach (['2030-01-07' => ['Identidad visual para una nueva marca', 5], '2030-01-12' => ['Un sitio web para dar el siguiente paso', 4], '2030-01-18' => ['Diseño de una experiencia digital', 5]] as $date => [$title, $rating]) {
            if ($date !== '2030-01-07') {
                $inquiry = $this->inquiry($client, $actor, \App\Enums\InquiryStatus::Accepted);
            }
            $inquiry->service->update(compact('title'));
            $reservation = $this->completeForReview($inquiry, compact('date'));
            $this->publishReview($reservation, $rating);
        }
        $asOf = \Carbon\Carbon::parse('2030-01-20 12:00:00', 'UTC');
        $this->travelTo($asOf);
        foreach (['2030-01-22' => 'Dirección de arte para tu proyecto', '2030-01-24' => 'Una web que conecta con tus clientes', '2030-01-26' => 'Revisión de una interfaz móvil'] as $date => $title) {
            $inquiry = $this->inquiry($client, $actor, \App\Enums\InquiryStatus::Accepted);
            $inquiry->service->update(compact('title'));
            $r = $this->proposeReservation($inquiry, compact('date'));
            $workflow->decide($actor, $r->id, 'confirm', ['version' => 1]);
        }
        foreach (['2030-01-23', '2030-01-25', '2030-01-27'] as $date) {
            $inquiry = $this->inquiry($client, $actor, \App\Enums\InquiryStatus::Accepted);
            $r = $this->proposeReservation($inquiry, compact('date'));
            if ($date === '2030-01-27') {
                $workflow->decide($client, $r->id, 'cancel', ['version' => 1]);
            }
        }
        foreach (['Identidad para una cafetería independiente', 'Diseño de la portada de un proyecto editorial'] as $title) {
            $pending = $this->inquiry(null, $actor);
            $pending->service->update(compact('title'));
            $this->message($pending->client, $pending, 'Mensaje sintético para revisión visual.');
        }
        $this->message($pending->client, $pending, 'Segundo mensaje sintético.');
        foreach (range(1, 2) as $unused) {
            $this->inquiry(null, $actor, \App\Enums\InquiryStatus::Rejected);
        }
        // The same user is also a CLIENT of a different provider: the dashboards must stay separate.
        $otherProvider = $this->account(\App\Enums\RoleName::Provider);
        $own = $this->inquiry($actor, $otherProvider, \App\Enums\InquiryStatus::Accepted);
        $own->service->update(['title' => 'Fotografía para una nueva colección']);
        $this->publishSchedule($otherProvider);
        $r = $this->proposeReservation($own, ['date' => '2030-01-21']);
        $workflow->decide($otherProvider, $r->id, 'confirm', ['version' => 1]);
        $this->message($otherProvider, $own, 'Mensaje sintético dirigido al cliente.');
        $this->message($otherProvider, $own, 'Confirmación sintética de detalles.');
        $ownProposal = $this->inquiry($actor, $otherProvider, \App\Enums\InquiryStatus::Accepted);
        $ownProposal->service->update(['title' => 'Revisión técnica de un equipo de trabajo']);
        $this->proposeReservation($ownProposal, ['date' => '2030-01-22']);
        $ownPending = $this->inquiry($actor, $otherProvider);
        $ownPending->service->update(['title' => 'Una página para compartir tus ideas']);
        $this->actingAs($actor, 'web');
        $save('/cuenta/cliente', 'client');
        foreach ([7, 30, 90] as $days) {
            $save('/cuenta/prestador/dashboard?period='.$days, 'provider-'.$days);
        }
        $routes['/cuenta/prestador/dashboard'] = 'provider-30.html';
        $save('/cuenta', 'account');
        $save('/cuenta/prestador', 'provider-form');
        $save('/mindbloom/inicio', 'legacy');
        $save('/servicios', 'catalog');
        file_put_contents($target.'/summaries.json', json_encode(['as_of_utc' => $asOf->toIso8601String(), 'client' => app(\App\Services\ClientDashboardSummary::class)->build($actor), 'provider' => app(\App\Services\ProviderDashboardMetrics::class)->build($actor, ['period' => '30'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $emptyClient = $this->account(\App\Enums\RoleName::Client);
        $emptyClient->update(['name' => 'Cuenta sin actividad']);
        $this->actingAs($emptyClient, 'web');
        $save('/cuenta/cliente', 'client-empty');
        $routes['/preview/client-empty'] = 'client-empty.html';
        $routes['/cuenta/cliente'] = 'client.html';
        $emptyProvider = $this->account(\App\Enums\RoleName::Provider);
        $emptyProvider->update(['name' => 'Cuenta sin actividad']);
        $emptyProvider->providerProfile()->create(['display_name' => 'Tu próximo proyecto']);
        $this->actingAs($emptyProvider, 'web');
        $save('/cuenta/prestador/dashboard', 'provider-empty');
        $routes['/preview/provider-empty'] = 'provider-empty.html';
        $routes['/cuenta/prestador/dashboard'] = 'provider-30.html';
        $noProfile = $this->account(\App\Enums\RoleName::Provider);
        $noProfile->update(['name' => 'Cuenta por completar']);
        $this->actingAs($noProfile, 'web');
        $save('/cuenta/prestador/dashboard', 'provider-setup');
        $routes['/preview/provider-setup'] = 'provider-setup.html';
        $routes['/cuenta/prestador/dashboard'] = 'provider-30.html';
        $this->app['auth']->guard('web')->logout();
        $save('/', 'home');
        $save('/login', 'login');
        $save('/registro', 'register');
        file_put_contents($target.'/routes.json', json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($target.'/safety.json', json_encode([
            'environment' => $this->app->environment(),
            'connection' => config('database.default'),
            'database' => config('database.connections.sqlite.database'),
            'environment_file_exists' => is_file($this->app->environmentFilePath()),
            'environment_directory_is_repository' => realpath($this->app->environmentPath()) === realpath(dirname(__DIR__)),
            'post_backend' => false,
        ], JSON_PRETTY_PRINT));
        file_put_contents($target.'/headers.json', json_encode(['Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'", 'X-Content-Type-Options' => 'nosniff'], JSON_PRETTY_PRINT));
        \Illuminate\Support\Facades\DB::disconnect();
        \Carbon\Carbon::setTestNow();
        echo "W2 export complete. Synthetic fixtures; SQLite :memory: only; no POST backend.\n";
    }
}
try {
    (new TekoraW2VisualExport('export'))->export($target, $origin);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'W2 export failed: '.$exception->getMessage()."\n");
    exit(1);
}
