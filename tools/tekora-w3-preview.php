<?php

// Reuse the guarded W2 export for the Tekora dashboards and central authentication.
// This first export requires a NEW target, a loopback origin and an absent .env.
require __DIR__.'/tekora-w2-preview.php';
require __DIR__.'/tekora-w3-preview-key.php';

final class TekoraW3Export extends \Tests\TestCase
{
    use \Tests\Support\ReviewFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-marketplace-no-env-'.bin2hex(random_bytes(16)));
        if (is_file($app->environmentFilePath())) {
            throw new RuntimeException('Preview requires an absent environment file.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function exportMarketplace(string $target, string $origin): void
    {
        $this->setUp();
        $this->app['url']->forceRootUrl($origin);
        $this->app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-w3-no-hot-'.bin2hex(random_bytes(8)));
        $routes = json_decode(file_get_contents($target.'/routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $routes = array_combine(array_map('tekoraMarketplacePreviewKey', array_keys($routes)), array_values($routes));
        $statusCodes = [];
        $queries = [];
        $save = function (string $uri, string $file, ?string $alias = null, int $status = 200) use ($target, &$routes, &$statusCodes, &$queries) {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $response = $this->get($uri);
            if ($response->getStatusCode() !== $status) {
                throw new RuntimeException('Export '.$uri.' returned '.$response->getStatusCode().': '.($response->exception?->getMessage() ?? 'Unexpected response.'));
            }
            $log = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
            file_put_contents($target.'/'.$file.'.html', $response->getContent());
            $key = tekoraMarketplacePreviewKey($alias ?? $uri);
            $routes[$key] = $file.'.html';
            $statusCodes[$key] = $status;
            $queries[$key] = ['queries' => count($log), 'write_queries' => count(array_filter($log, fn ($query) => preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query'])))];
        };
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $provider->update(['name' => 'Alex · Demostración', 'email' => 'privado-w3@example.test']);
        $profile = $provider->providerProfile;
        $profile->update(['display_name' => 'Estudio Nodo', 'headline' => 'Diseño visual y experiencias digitales',
            'bio' => "Un espacio para dar forma a ideas con identidad propia. Diseño de marcas, piezas editoriales y experiencias digitales.\n\nCada proyecto comienza con una conversación: cuéntanos qué necesitas y revisamos juntos su alcance."]);
        $category = $this->category('diseno');
        $category->update(['name' => 'Diseño y creatividad']);
        $tech = $this->category('tecnologia');
        $tech->update(['name' => 'Tecnología']);
        $main = $inquiry->service;
        $main->update(['title' => 'Diseño de una identidad visual', 'service_category_id' => $category->id,
            'description' => "Una identidad que conecta tu idea con las personas. Revisamos contigo la dirección visual y el alcance de las piezas antes de comenzar.\n\nEl servicio puede incluir exploración conceptual, composición tipográfica y una propuesta de lenguaje gráfico, según lo que acuerdes con el prestador. Comparte el contexto de tu proyecto al enviar la solicitud.\n\nLos detalles, tiempos y entregables se conversan directamente; esta publicación no supone un contrato ni una reserva confirmada."]);
        $this->publishReview($reservation, 5, 'Una comunicación clara y una propuesta visual muy cuidada.');
        foreach ([4, 5, 5, 4, 5, 4] as $index => $rating) {
            $this->publishReview($this->completeForReview($inquiry), $rating, ['El proceso fue ordenado y pude comentar cada avance.', 'Me ayudó a convertir una idea inicial en una dirección clara.', 'Una experiencia cercana y con atención a los detalles.'][$index % 3]);
        }
        $withdrawn = $this->publishReview($this->completeForReview($inquiry), 1, 'W3-RESEÑA-RETIRADA-PRIVADA');
        app(\App\Services\ReviewWorkflow::class)->withdraw($client, $withdrawn->id);
        $titles = ['Diseño editorial para tu siguiente publicación', 'Diseño de una presentación con identidad', 'Diseño de piezas para redes sociales', 'Diseño de una interfaz para tu proyecto', 'Diseño de un sistema visual de marca', 'Diseño de empaques y etiquetas', 'Diseño de una guía de estilo', 'Diseño de una landing para tu idea', 'Diseño de material para un evento', 'Diseño de una portada editorial', 'Diseño de señalética y espacios', 'Diseño de una colección gráfica', 'Diseño de un portafolio digital'];
        foreach ($titles as $index => $title) {
            $this->service($provider, ['title' => $title, 'service_category_id' => $category->id, 'modality' => $index % 3 === 0 ? 'both' : 'remote', 'description' => 'Una propuesta visual para comunicar con claridad. Conversa con el prestador para definir el alcance y los entregables de tu proyecto.']);
        }
        $emptyReview = $this->service($provider, ['title' => 'Revisión de un equipo de trabajo', 'service_category_id' => $tech->id, 'description' => 'Diagnóstico inicial de un equipo de cómputo. El alcance se confirma con el prestador.']);
        foreach (['Lía · Estudio Norte', 'Taller Prisma', 'Nexo Digital', 'Casa Línea', 'Punto Abierto', 'Nodo Técnico', 'Estudio Bruma', 'Trazo Local', 'Forma Estudio', 'Laboratorio Uno', 'Estudio Marea', 'Luz y Espacio'] as $index => $name) {
            $owner = $this->account(\App\Enums\RoleName::Provider);
            $service = $this->service($owner, ['title' => ['Desarrollo de sitios web', 'Soporte técnico de computadoras', 'Revisión de interfaces digitales'][$index % 3], 'service_category_id' => $tech->id, 'description' => ['Desarrollo web para presentar tu proyecto. El alcance y los entregables se conversan con el prestador.', 'Revisión técnica de equipos. Comparte el contexto y confirma los detalles antes de comenzar.', 'Revisión de una interfaz digital para mejorar su claridad. Conversa sobre el alcance de tu proyecto.'][$index % 3], 'modality' => $index % 2 ? 'remote' : 'onsite']);
            $owner->providerProfile->update(['display_name' => $name, 'headline' => ['Desarrollo y tecnología', 'Soporte y mantenimiento', 'Diseño de experiencias digitales'][$index % 3], 'bio' => 'Cuéntame sobre tu proyecto para conversar sobre lo que necesitas y el alcance de mi servicio.']);
        }
        $this->travelTo(\Carbon\Carbon::parse('2030-02-01 12:00:00', 'UTC'));
        app(\App\Services\ProviderAvailability::class)->save($provider, ['revision' => 1, 'timezone' => 'America/Mexico_City', 'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '09:00', 'end' => '18:00'], range(1, 5)), 'blocked_dates' => ['2030-02-12']]);
        $this->app['auth']->guard('web')->logout();
        $save('/registro?intent=provider', 'market-register-provider');
        $save('/servicios', 'market-catalog');
        $save('/servicios?page=2', 'market-catalog-page-2');
        $save('/servicios?page=3', 'market-catalog-page-3');
        $filters = ['q' => 'Diseño', 'category' => 'diseno', 'modality' => 'remote'];
        $search = '/servicios?'.http_build_query($filters);
        $save($search, 'market-search');
        $save($search.'&page=2', 'market-search-page-2');
        $save('/servicios?'.http_build_query(['q' => 'Diseño']), 'market-search-only');
        $save('/servicios?'.http_build_query(['q' => 'Diseño', 'page' => 2]), 'market-search-only-page-2');
        $save('/servicios?category=diseno', 'market-category');
        $save('/servicios?category=diseno&page=2', 'market-category-page-2');
        $save('/servicios?modality=remote', 'market-modality');
        $save('/servicios?modality=remote&page=2', 'market-modality-page-2');
        $save('/servicios?q=sin-resultados', 'market-empty');
        $save('/servicios?modality=invalid', 'market-invalid', null, 422);
        $routes['/preview/invalid-filters'] = 'market-invalid.html';
        $statusCodes['/preview/invalid-filters'] = 422;
        $save('/servicios/'.$main->id, 'market-service');
        $save('/servicios/'.$main->id.'?reviews_page=2', 'market-service-reviews-2');
        $save('/servicios/'.$emptyReview->id, 'market-service-no-reviews', '/preview/service-no-reviews');
        $save('/prestadores', 'market-providers');
        $save('/prestadores?page=2', 'market-providers-page-2');
        $profilePath = '/prestadores/'.$profile->id;
        foreach ([[1, 1], [2, 1], [1, 2], [2, 2]] as [$page, $reviewPage]) {
            $save($profilePath.'?page='.$page.'&reviews_page='.$reviewPage, 'market-profile-'.$page.'-'.$reviewPage);
        }
        $save($profilePath, 'market-profile');
        foreach (\App\Models\Service::publiclyVisible()->where('id', '!=', $main->id)->pluck('id') as $id) {
            $save('/servicios/'.$id, 'market-extra-service-'.$id);
        }
        foreach (\App\Models\ProviderProfile::whereHas('services', fn ($query) => $query->publiclyVisible())->where('id', '!=', $profile->id)->pluck('id') as $id) {
            $save('/prestadores/'.$id, 'market-extra-profile-'.$id);
        }
        $create = route('inquiries.create', $main, false);
        $redirect = $this->get($create);
        if ($redirect->getStatusCode() !== 302 || $redirect->headers->get('Location') !== route('login')) {
            throw new RuntimeException('Guest request must redirect to web login.');
        }
        $redirects = [tekoraMarketplacePreviewKey($create) => '/login'];
        $client = $this->account(\App\Enums\RoleName::Client);
        $this->actingAs($client, 'web');
        $save('/servicios/'.$main->id, 'market-client-service', '/preview/client-service');
        $save($create, 'market-request-form', '/preview/request-form');
        $this->actingAs($provider, 'web');
        $save('/servicios/'.$main->id, 'market-owner-service', '/preview/owner-service');
        $onlyProvider = $this->account(\App\Enums\RoleName::Provider);
        $this->actingAs($onlyProvider, 'web');
        $save('/servicios/'.$main->id, 'market-provider-service', '/preview/provider-service');
        $this->app['auth']->guard('web')->logout();
        $main->update(['title' => str_repeat('Una propuesta visual para explorar ', 4), 'description' => str_repeat('Texto público con contexto y alcance. ', 40).'<script>W3-XSS</script>']);
        $save('/servicios/'.$main->id, 'market-long-text', '/preview/long-text');
        \App\Models\Service::query()->update(['status' => 'paused']);
        $save('/prestadores', 'market-providers-empty', '/preview/providers-empty');
        foreach (['routes' => $routes, 'status-codes' => $statusCodes, 'redirects' => $redirects, 'queries' => $queries] as $name => $data) {
            file_put_contents($target.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        file_put_contents($target.'/marketplace-fixtures.json', json_encode(['source' => 'Synthetic memory-only fixtures; no production activity.', 'as_of_utc' => now('UTC')->toIso8601String(), 'service_path' => '/servicios/'.$main->id, 'profile_path' => $profilePath, 'search_path' => $search, 'request_path' => $create], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        \Illuminate\Support\Facades\DB::disconnect();
        \Carbon\Carbon::setTestNow();
        echo "W3 export complete. Real GET controllers, synthetic SQLite :memory: fixtures, no POST backend.\n";
    }
}
try {
    (new TekoraW3Export('exportMarketplace'))->exportMarketplace($target, $origin);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'W3 export failed: '.$exception->getMessage()."\n");
    exit(1);
}
