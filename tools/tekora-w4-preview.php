<?php

// Reuse W3 unchanged: NEW target, loopback, absent .env, testing + memory SQLite.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$w4Origin = parse_url($argv[2] ?? '');
if (($w4Origin['scheme'] ?? '') !== 'http' || ($w4Origin['host'] ?? '') !== '127.0.0.1') {
    fwrite(STDERR, "Use a NEW target and an explicit http://127.0.0.1:PORT origin.\n");
    exit(1);
}
require __DIR__.'/tekora-w3-preview.php';

final class TekoraW4Export extends \Tests\TestCase
{
    use \Tests\Support\ReviewFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-ops-no-env-'.bin2hex(random_bytes(16)));
        if (is_file($app->environmentFilePath())) {
            throw new RuntimeException('The preview must never read an environment file.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function exportOperations(string $target, string $origin): void
    {
        $this->setUp();
        $this->app['url']->forceRootUrl($origin);
        $this->app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-w4-no-hot-'.bin2hex(random_bytes(8)));
        $routes = json_decode(file_get_contents($target.'/routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $statuses = json_decode(file_get_contents($target.'/status-codes.json'), true, flags: JSON_THROW_ON_ERROR);
        $pages = [];
        $queries = [];
        $record = function ($response, string $uri, string $name, string $role, int $status, string $source) use ($target, &$routes, &$statuses, &$pages) {
            if ($response->getStatusCode() !== $status) {
                throw new RuntimeException($name.' returned '.$response->getStatusCode().': '.($response->exception?->getMessage() ?? 'Unexpected response'));
            }
            $file = 'ops-'.$name.'.html';
            file_put_contents($target.'/'.$file, $response->getContent());
            $routes[tekoraMarketplacePreviewKey($uri)] = $file;
            $routes['/preview/w4/'.$name] = $file;
            $statuses[tekoraMarketplacePreviewKey($uri)] = $status;
            $statuses['/preview/w4/'.$name] = $status;
            $pages[$name] = ['path' => '/preview/w4/'.$name, 'original_uri' => $uri, 'role' => $role, 'status' => $status, 'source' => $source];
        };
        $save = function (string $uri, string $name, string $role, int $status = 200) use (&$record, &$queries) {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $response = $this->get($uri);
            $log = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $queries[$name] = ['count' => count($log), 'writes' => count(array_filter($log, fn ($q) => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q['query'])))];
            $record($response, $uri, $name, $role, $status, 'Real controller GET over synthetic memory fixtures');

            return $response;
        };
        [$client, $provider, $accepted] = $this->reservationContext();
        $client->update(['name' => 'Lía · Demostración']);
        $provider->update(['name' => 'Alex · Demostración']);
        $provider->providerProfile->update(['display_name' => 'Estudio Nodo · Demostración', 'headline' => 'Diseño y experiencias digitales', 'bio' => 'Damos forma a ideas con identidad propia. Datos sintéticos para revisar W4.']);
        $accepted->service->update(['title' => 'Identidad visual para tu siguiente proyecto']);
        $completed = $this->completeForReview($accepted);
        $review = $this->publishReview($completed, 5, 'La comunicación fue clara y pude revisar cada avance. Reseña sintética para validación.');
        $forReview = $this->completeForReview($accepted);
        $withdrawn = $this->publishReview($this->completeForReview($accepted), 4, 'Registro retirado de demostración.');
        app(\App\Services\ReviewWorkflow::class)->withdraw($client, $withdrawn->id);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-20 12:00:00', 'UTC'));
        $fresh = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
        $fresh->service->update(['title' => 'Diseño de una experiencia digital']);
        $pending = $this->inquiry($client, $provider);
        $pending->service->update(['title' => 'Una web que conecta con tus clientes']);
        for ($i = 1; $i <= 25; $i++) {
            $this->message($i % 2 ? $provider : $client, $pending, $i === 25 ? str_repeat('Podemos conversar sobre el alcance, la identidad y las piezas del proyecto. ', 22) : ['¿Podemos revisar la dirección visual antes de comenzar?', 'Sí, comparte el contexto y conversamos sobre los entregables.'][$i % 2]);
        }
        $readonly = $this->inquiry($client, $provider);
        $this->message($provider, $readonly, 'Conservamos esta conversación para tu seguimiento.');
        app(\App\Services\InquiryWorkflow::class)->decide($client, $readonly->id, \App\Enums\InquiryAction::Cancel);
        $requested = $this->proposeReservation($fresh, ['date' => '2030-01-23']);
        $confirmedInquiry = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
        $confirmed = $this->proposeReservation($confirmedInquiry, ['date' => '2030-01-24']);
        app(\App\Services\ReservationWorkflow::class)->decide($provider, $confirmed->id, 'confirm', ['version' => 1]);
        $expiredInquiry = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
        $expired = $this->proposeReservation($expiredInquiry, ['date' => '2030-01-21']);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-22 12:00:00', 'UTC'));
        app(\App\Services\ProviderAvailability::class)->save($provider, ['revision' => 1, 'timezone' => 'America/Mexico_City', 'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '09:00', 'end' => '18:00'], range(1, 5)), 'blocked_dates' => ['2030-02-12', '2030-02-26']]);
        $inactive = $this->category('categoria-inactiva-w4');
        $inactive->update(['is_active' => false]);
        for ($i = 0; $i < 13; $i++) {
            $extra = $this->inquiry($client, $provider, \App\Enums\InquiryStatus::Accepted);
            $extra->service->update(['title' => 'Exploración visual · Proyecto '.($i + 1)]);
        }
        // Keep the meaningful draft/paused/inactive examples on the first management page.
        $draft = $this->service($provider, ['title' => 'Diseño editorial · Borrador', 'status' => 'draft']);
        $this->service($provider, ['title' => 'Una presentación con identidad · Pausado', 'status' => 'paused']);
        $inactiveService = $this->service($provider, ['title' => 'Servicio en categoría anterior', 'service_category_id' => $inactive->id]);
        $available = $this->service($provider, ['title' => 'Diseño para tu siguiente idea']);
        $notice = $client->activityNotifications()->first();
        if ($notice) {
            app(\App\Services\NotificationInbox::class)->readActivity($client, $notice->id, []);
        }
        $this->actingAs($client, 'web');
        $save(route('inquiries.create', $available, false), 'request-create', 'cliente');
        $this->withSession(['_old_input' => ['message' => 'Mi necesidad anterior: conservar este texto al revisar los errores.'], 'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['message' => 'Revisa la descripción de tu necesidad antes de enviar.']))]);
        $save(route('inquiries.create', $available, false), 'request-validation', 'cliente · validación');
        session()->flush();
        $save('/cuenta/solicitudes', 'requests-sent', 'cliente');
        $save('/cuenta/solicitudes?page=2', 'requests-page-2', 'cliente');
        $save('/cuenta/solicitudes?status=pending', 'requests-filtered', 'cliente');
        $detail = route('inquiries.show', $pending, false);
        $response = $save($detail, 'request-detail', 'cliente');
        $cursor = $response->viewData('messages')->nextPageUrl();
        $olderPage = $save($cursor, 'conversation-older', 'cliente');
        $save('/cuenta/mensajes', 'conversations', 'cliente');
        $save(route('inquiries.show', $readonly, false), 'conversation-readonly', 'cliente');
        $save('/cuenta/reservas', 'reservations', 'cliente');
        $save(route('reservations.create', $accepted, false), 'reservation-propose', 'cliente');
        foreach (['requested' => $requested, 'confirmed' => $confirmed, 'expired' => $expired, 'completed' => $completed] as $name => $r) {
            $save(route('reservations.show', $r, false), 'reservation-'.$name, 'cliente');
        }
        $save('/cuenta/resenas', 'reviews', 'cliente');
        $save(route('reviews.create', $forReview, false), 'review-create', 'cliente');
        $save(route('reviews.show', $review, false), 'review-published', 'cliente');
        $save(route('reviews.show', $withdrawn, false), 'review-withdrawn', 'cliente');
        $save('/cuenta/notificaciones', 'notifications', 'cliente');
        $save('/cuenta/notificaciones?page=2', 'notifications-page-2', 'cliente');
        $client->roles()->detach();
        $this->actingAs($client->fresh(), 'web');
        $save('/cuenta/notificaciones', 'notifications-unavailable', 'cuenta sin permiso actual de destino');
        $client->roles()->attach(\App\Models\Role::where('slug', 'client')->value('id'));
        $this->actingAs($client->fresh(), 'web');
        $save('/cuenta/solicitudes?status=invalid', 'request-invalid', 'cliente', 422);
        $save('/cuenta/notificaciones?page=0', 'notifications-error', 'cliente', 422);
        $this->actingAs($provider, 'web');
        $save('/cuenta/solicitudes/recibidas', 'requests-received', 'cuenta dual · prestador');
        $save($detail, 'request-provider', 'cuenta dual · prestador');
        $save(route('reservations.show', $requested, false), 'reservation-provider', 'cuenta dual · prestador');
        $save('/cuenta/disponibilidad', 'availability', 'cuenta dual · prestador');
        $save('/cuenta/servicios', 'services', 'cuenta dual · prestador');
        $save('/cuenta/servicios/crear', 'service-create', 'cuenta dual · prestador');
        $save(route('provider-services.edit', $draft, false), 'service-edit', 'cuenta dual · prestador');
        $save(route('provider-services.edit', $inactiveService, false), 'service-inactive-category', 'cuenta dual · prestador');
        $save('/cuenta/prestador', 'profile', 'cuenta dual · prestador');
        $save(route('provider-profiles.show', $provider->providerProfile, false), 'profile-show', 'cuenta dual · prestador');
        // A dual account also has its own client requests, separate from received work.
        $own = $this->inquiry($provider);
        $save('/cuenta/solicitudes', 'dual-sent', 'cuenta dual · cliente');
        $save(route('inquiries.show', $own, false), 'dual-request-detail', 'cuenta dual · cliente');
        // Export the actual destinations linked by these fixtures; no generic fallback page.
        // Curated matrix entries stay separate from additional navigable snapshots.
        $linked = [];
        $this->actingAs($client->fresh(), 'web');
        $name = 'linked-conversation-newer';
        $save($olderPage->viewData('messages')->previousPageUrl(), $name, 'cliente');
        $linked[$name] = $pages[$name];
        unset($pages[$name]);
        foreach (['/cuenta/solicitudes?page=1' => 'linked-requests-first', '/cuenta/mensajes?page=2' => 'linked-conversations-page-2', '/cuenta/notificaciones?page=1' => 'linked-notifications-first'] as $uri => $name) {
            $save($uri, $name, 'cliente');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        foreach (\App\Models\ServiceInquiry::where('client_user_id', $client->id)->get() as $linkedInquiry) {
            $name = 'linked-request-'.$linkedInquiry->id;
            $save(route('inquiries.show', $linkedInquiry, false), $name, 'cliente');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        foreach (\App\Models\ServiceReservation::where('client_user_id', $client->id)->get() as $r) {
            $name = 'linked-reservation-'.$r->id;
            $save(route('reservations.show', $r, false), $name, 'cliente');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        foreach (\App\Models\Service::publiclyVisible()->get() as $publicService) {
            $name = 'linked-public-service-'.$publicService->id;
            $save(route('catalog.show', $publicService, false), $name, 'público');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
            $create = route('inquiries.create', $publicService, false);
            $response = $this->get($create);
            if ($response->getStatusCode() === 302) {
                $redirects = json_decode(file_get_contents($target.'/redirects.json'), true);
                $location = $response->headers->get('Location');
                if (! str_starts_with($location, $origin.'/')) {
                    throw new RuntimeException('Nonlocal fixture redirect.');
                }
                $redirects[tekoraMarketplacePreviewKey($create)] = substr($location, strlen($origin));
                file_put_contents($target.'/redirects.json', json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $name = 'linked-request-create-'.$publicService->id;
                $record($response, $create, $name, 'cliente', 200, 'Real controller GET over synthetic memory fixtures');
                $linked[$name] = $pages[$name];
                unset($pages[$name]);
            }
        }
        foreach (['/servicios' => 'linked-catalog', '/prestadores' => 'linked-providers', route('public-providers.show', $provider->providerProfile, false) => 'linked-public-profile'] as $uri => $name) {
            $save($uri, $name, 'público');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        foreach (['/servicios?page=2' => 'linked-catalog-page-2', '/cuenta/reservas?page=2' => 'linked-reservations-page-2'] as $uri => $name) {
            $save($uri, $name, 'cliente');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        foreach (\App\Models\ProviderProfile::whereHas('services', fn ($query) => $query->publiclyVisible())->get() as $publicProfile) {
            foreach (['' => 'profile', '?page=2' => 'profile-page-2'] as $query => $suffix) {
                $name = 'linked-'.$suffix.'-'.$publicProfile->id;
                $save(route('public-providers.show', $publicProfile, false).$query, $name, 'público');
                $linked[$name] = $pages[$name];
                unset($pages[$name]);
            }
        }
        $this->actingAs($provider, 'web');
        $name = 'linked-services-page-2';
        $save('/cuenta/servicios?page=2', $name, 'prestador');
        $linked[$name] = $pages[$name];
        unset($pages[$name]);
        $name = 'linked-received-page-2';
        $save('/cuenta/solicitudes/recibidas?page=2', $name, 'prestador');
        $linked[$name] = $pages[$name];
        unset($pages[$name]);
        foreach ($provider->providerProfile->services as $ownedService) {
            $name = 'linked-service-edit-'.$ownedService->id;
            $save(route('provider-services.edit', $ownedService, false), $name, 'prestador');
            $linked[$name] = $pages[$name];
            unset($pages[$name]);
        }
        file_put_contents($target.'/w4-linked-pages.json', json_encode($linked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // Keep POST-only failure views honest: presentation fixtures, not a successful operation.
        foreach (['inquiries.conflict' => ['detailUrl' => $detail], 'inquiries.message-error' => ['detailUrl' => $detail], 'inquiries.rate-limited' => [], 'conversations.read-error' => ['detailUrl' => $detail], 'reservations.error' => [], 'reviews.error' => [], 'availability.error' => []] as $view => $extra) {
            $name = str_replace('.', '-', $view);
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $response = response()->view($view, $extra + ['message' => 'Hay otra operación en curso. Revisa el estado actual antes de reintentar.'], 409);
            $log = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $queries[$name] = ['count' => count($log), 'writes' => count(array_filter($log, fn ($q) => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q['query'])))];
            $record($response, '/preview/w4/'.$name, $name, 'fallback', 409, 'Existing POST-only failure Blade rendered with synthetic message; no operation simulated');
        }
        $empty = $this->account(\App\Enums\RoleName::Client, \App\Enums\RoleName::Provider);
        $empty->update(['name' => 'Cuenta sin actividad · Demostración']);
        $this->actingAs($empty, 'web');
        foreach (['/cuenta/solicitudes' => 'requests-empty', '/cuenta/mensajes' => 'conversations-empty', '/cuenta/reservas' => 'reservations-empty', '/cuenta/resenas' => 'reviews-empty', '/cuenta/notificaciones' => 'notifications-empty', '/cuenta/servicios' => 'services-setup', '/cuenta/prestador' => 'profile-create'] as $uri => $name) {
            $save($uri, $name, 'cuenta dual · vacía');
        }
        $empty->providerProfile()->create(['display_name' => 'Perfil por completar · Demostración']);
        $save('/cuenta/servicios', 'services-empty', 'prestador sin servicios');
        $save('/cuenta/disponibilidad', 'availability-empty', 'prestador sin horarios');
        // Restore canonical routes to a coherent populated actor. Role aliases remain explicit.
        foreach (['request-create', 'requests-sent', 'conversations', 'reservations', 'reviews', 'notifications', 'services', 'profile', 'availability', 'request-detail'] as $name) {
            $routes[tekoraMarketplacePreviewKey($pages[$name]['original_uri'])] = 'ops-'.$name.'.html';
        }
        foreach (['routes' => $routes, 'status-codes' => $statuses, 'w4-pages' => $pages, 'w4-queries' => $queries] as $name => $data) {
            file_put_contents($target.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        \Illuminate\Support\Facades\DB::disconnect();
        \Carbon\Carbon::setTestNow();
        echo 'W4 export: '.count($pages)." pages; synthetic SQLite :memory:; no browser POST backend.\n";
    }
}
try {
    (new TekoraW4Export('exportOperations'))->exportOperations($target, $origin);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'W4 export failed: '.$exception->getMessage()."\n");
    exit(1);
}
