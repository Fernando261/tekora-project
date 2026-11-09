<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

#[Group('web-w3')]
class TekoraW3Test extends TestCase
{
    use ReviewFixtures;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public static function pages(): array
    {
        return [['catalog.index'], ['catalog.show'], ['public-providers.index'], ['public-providers.show']];
    }

    #[DataProvider('pages')]
    public function test_public_pages_have_tekora_identity_safe_metadata_and_native_navigation(string $route): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $url = route($route, str_ends_with($route, '.show') ? ($route === 'catalog.show' ? $service->id : $service->provider_profile_id) : []);
        $response = $this->get($url)->assertOk()->assertDontSee('MindBloom')->assertSee('tk-marketplace');
        $xpath = $this->document($response->getContent());
        $this->assertStringEndsWith(' · Tekora', $xpath->query('//title')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//meta[@name="description" and string-length(@content)>20]')->length);
        $this->assertSame(1, $xpath->query('//main[@id="catalog-content"]//h1')->length);
        $this->assertSame(0, $xpath->query('//script[not(@src)]')->length);
        $this->assertSame(0, $xpath->query('//img')->length, 'No fictitious service/provider photos.');
        $this->assertSame(0, $xpath->query('//*[@aria-hidden="true"]//a | //*[@aria-hidden="true"]//button')->length);
        $this->assertSame(1, $xpath->query('//header/nav/a[@href="'.route('catalog.index').'"]')->length);
        $ids = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//@id')));
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("'unsafe-eval'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('css/catalog.css', $response->getContent());
    }

    public static function actors(): array
    {
        return ['guest' => [[], false, true], 'client' => [[RoleName::Client], false, true], 'provider' => [[RoleName::Provider], false, false],
            'dual' => [[RoleName::Client, RoleName::Provider], false, true], 'owner' => [[RoleName::Client, RoleName::Provider], true, false], 'admin' => [[RoleName::Admin], false, false]];
    }

    #[DataProvider('actors')]
    public function test_request_cta_keeps_guest_client_provider_dual_and_ownership_rules(array $roles, bool $own, bool $canRequest): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($owner);
        if ($roles) {
            $this->actingAs($own ? $owner : $this->account(...$roles), 'web');
        }
        $response = $this->get(route('catalog.show', $service))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame($canRequest ? 1 : 0, $xpath->query('//a[@href="'.route('inquiries.create', $service).'"]')->length);
        $response->assertSee('Enviar una solicitud no crea reservas, pagos ni contratos automáticos.')->assertSee('sin verificación profesional');
        if ($own) {
            $response->assertSee('No puedes solicitarlo desde tu cuenta.');
        }
        $create = $this->get(route('inquiries.create', $service));
        if (! $roles) {
            $create->assertRedirect(route('login'));
        } elseif ($canRequest) {
            $create->assertOk();
        } else {
            $create->assertForbidden();
        }
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_real_get_search_pagination_and_filters_survive_the_new_cards(): void
    {
        $owner = $this->account(RoleName::Provider);
        foreach (range(1, 14) as $index) {
            $this->service($owner, ['title' => 'Diseño 100% literal '.$index, 'modality' => $index % 2 ? 'remote' : 'both']);
        }
        $this->service($owner, ['title' => 'NO-COINCIDE', 'modality' => 'onsite']);
        $filters = ['q' => '100%', 'category' => 'soporte-tecnico', 'modality' => 'remote'];
        $response = $this->get(route('catalog.index', $filters))->assertOk()->assertDontSee('NO-COINCIDE');
        $this->assertSame(14, $response->viewData('services')->total());
        $xpath = $this->document($response->getContent());
        $this->assertSame(12, $xpath->query('//article[@data-market-card]')->length);
        $this->assertSame(1, $xpath->query('//form[@method="GET" and @role="search"]')->length);
        $this->assertSame(['q', 'category', 'modality'], array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//form//input/@name | //form//select/@name'))));
        $this->assertSame('120', $xpath->query('//input[@name="q"]/@maxlength')->item(0)->nodeValue);
        $next = $xpath->query('//nav[contains(@class,"catalog-pagination")]//a[@rel="next"]/@href')->item(0)->nodeValue;
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page' => '2'], $query);
        $pageTwo = $this->get($next)->assertOk()->assertSee('14 servicios disponibles');
        $this->assertCount(2, $pageTwo->viewData('services'));
        $this->get(route('catalog.index', ['q' => "%' OR 1=1 --"]))->assertOk()->assertSee('No encontramos servicios');
        $this->get(route('catalog.index', ['q' => '_']))->assertOk()->assertSee('No encontramos servicios');
    }

    public static function invalidFilters(): array
    {
        return [['q[]=bad'], ['q='.str_repeat('a', 121)], ['category=missing'], ['modality=invalid'], ['page=0']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_keep_real_422_errors_and_a_safe_return_link(string $query): void
    {
        $response = $this->get('/servicios?'.$query)->assertStatus(422)->assertSee('Revisa los filtros de búsqueda')->assertDontSee('SQLSTATE');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@role="alert"]//h1')->length);
        $this->assertSame(1, $xpath->query('//*[@role="alert"]//a[@href="'.route('catalog.index').'"]')->length);
    }

    public function test_visibility_counts_and_empty_states_use_only_public_services_and_providers(): void
    {
        $visible = $this->service($this->account(RoleName::Provider), ['title' => 'W3-PUBLIC']);
        $private = $this->service($this->account(RoleName::Provider), ['title' => 'W3-PRIVATE-DRAFT', 'status' => 'draft']);
        $paused = $this->service($private->providerProfile->user, ['title' => 'W3-PRIVATE-PAUSED', 'status' => 'paused']);
        $this->get('/servicios')->assertOk()->assertSee('1 servicio disponible')->assertDontSee($private->title)->assertDontSee($paused->title);
        $this->get('/prestadores')->assertOk()->assertSee('1 servicio publicado')->assertDontSee($private->providerProfile->display_name);
        foreach ([$private, $paused] as $service) {
            $this->get(route('catalog.show', $service))->assertNotFound();
        }
        $this->get(route('public-providers.show', $private->provider_profile_id))->assertNotFound();
        $visible->providerProfile->user->roles()->detach();
        $this->get(route('catalog.show', $visible))->assertNotFound();
        $this->get(route('public-providers.show', $visible->provider_profile_id))->assertNotFound();
        $this->get('/servicios')->assertOk()->assertSee('0 servicios disponibles')->assertSee('No encontramos servicios');
        $this->get('/prestadores')->assertOk()->assertSee('Aún no hay prestadores con servicios publicados');
    }

    public function test_schedule_and_real_reviews_keep_their_meaning_without_exposing_private_identity(): void
    {
        [$client, $owner, $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation, 4, 'Un comentario público de una reserva completada.');
        foreach ([route('catalog.show', $inquiry->service_id), route('public-providers.show', $owner->providerProfile)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('4.0 / 5')->assertSee('1 reseña verificada')->assertSee('Servicio verificado')->assertSee('Horarios orientativos, sujetos a confirmación con el prestador')->assertSee('America/Mexico_City')->assertSee('No acredita un pago ni una verificación profesional.')->assertDontSee($client->name)->assertDontSee($client->email);
            $xpath = $this->document($response->getContent());
            $this->assertSame(0, $xpath->query('//input[@name="starts_at" or @name="start_datetime"]')->length);
            $this->assertSame(0, $xpath->query('//a[contains(@href,"/cuenta/reservas/")]')->length);
        }
        $empty = $this->service($owner);
        $this->get(route('catalog.show', $empty))->assertOk()->assertSee('Aún no hay reseñas verificadas')->assertDontSee('0.0 / 5');
    }

    public function test_service_and_review_paginators_remain_independent_on_public_profiles(): void
    {
        [, $owner, $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation);
        foreach (range(1, 5) as $unused) {
            $this->publishReview($this->completeForReview($inquiry));
        }
        foreach (range(1, 13) as $unused) {
            $this->service($owner);
        }
        $response = $this->get(route('public-providers.show', $owner->providerProfile).'?page=2&reviews_page=2')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(2, $xpath->query('//nav[contains(@class,"catalog-pagination")]')->length);
        $services = $xpath->query('//nav[@aria-label="Páginas de servicios"]//a[@rel="prev"]/@href')->item(0)->nodeValue;
        $reviews = $xpath->query('//nav[@aria-label="Páginas de reseñas"]//a[@rel="prev"]/@href')->item(0)->nodeValue;
        parse_str(parse_url($services, PHP_URL_QUERY), $serviceQuery);
        parse_str(parse_url($reviews, PHP_URL_QUERY), $reviewQuery);
        $this->assertEquals(['page' => '1', 'reviews_page' => '2'], $serviceQuery);
        $this->assertEquals(['page' => '2', 'reviews_page' => '1'], $reviewQuery);
        $this->assertSame(2, $xpath->query('//article[@data-market-card]')->length);
    }

    public function test_long_public_text_is_escaped_in_head_metadata_cards_and_profile_identity(): void
    {
        $owner = $this->account(RoleName::Provider);
        $title = '<script>W3-XSS</script> '.str_repeat('Título largo ', 10);
        $service = $this->service($owner, ['title' => $title, 'description' => '<img src=x onerror=W3-XSS()> '.str_repeat('Contenido público. ', 80)]);
        $owner->providerProfile->update(['display_name' => '<svg onload=W3-XSS()>', 'headline' => 'Texto <script>sin ejecutar</script>']);
        foreach (['/servicios', route('catalog.show', $service), '/prestadores', route('public-providers.show', $owner->providerProfile)] as $url) {
            $response = $this->get($url)->assertOk()->assertDontSee('<script>W3-XSS', false)->assertDontSee('<img src=x', false)->assertDontSee('<svg onload=', false);
            $xpath = $this->document($response->getContent());
            $this->assertSame(0, $xpath->query('//script[not(@src)] | //@onerror | //@onload')->length);
        }
    }

    public function test_public_gets_do_not_write_business_data_or_add_private_fields_to_the_api(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
        $before = $this->reservationRows($tables);
        foreach (['/servicios', route('catalog.show', $service), '/prestadores', route('public-providers.show', $service->provider_profile_id)] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame($before, $this->reservationRows($tables));
        $detail = $this->get('/api/v1/services/'.$service->id)->assertOk()->json('data');
        $this->assertSame(['id', 'title', 'description', 'modality', 'category', 'provider'], array_keys($detail));
        $this->assertSame(['id', 'display_name', 'headline', 'bio'], array_keys($detail['provider']));
        $this->assertCount(37, glob(database_path('migrations/*.php')));
        $this->assertCount(21, array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/')));
    }

    public function test_modern_home_retires_promotional_legacy_links_and_private_history_keeps_its_routes(): void
    {
        $this->get('/')->assertOk()->assertDontSee('MindBloom')->assertDontSee('href="'.route('home').'"', false);
        $this->get('/mindbloom/inicio')->assertRedirect(route('login'));
        $this->actingAs($this->account(RoleName::Client))->get('/mindbloom/inicio')->assertOk()->assertSee('Bienvenido a MindBloom')->assertDontSee('tk-marketplace');
        $this->get('/cuenta')->assertOk()->assertSee('MindBloom anterior')->assertSee('href="'.route('home').'"', false);
        $professional = app('router')->getRoutes()->getByName('profesionista.dashboard');
        $this->assertContains('auth:profesionista', $professional->gatherMiddleware());
        $this->assertSame('pdf/emociones/tarjetas', app('router')->getRoutes()->getByName('pdf.emotions.cards')->uri());
    }

    public static function assetModes(): array
    {
        return [['current'], ['no-build']];
    }

    #[DataProvider('assetModes')]
    public function test_marketplace_assets_are_referenced_with_and_without_a_vite_build(string $mode): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'tekora-w3-hot-');
        $temporaryPublic = $mode === 'no-build' ? sys_get_temp_dir().'/tekora-w3-public-'.bin2hex(random_bytes(8)) : null;
        if ($temporaryPublic) {
            mkdir($temporaryPublic);
            $this->app->usePublicPath($temporaryPublic);
        }
        $this->beforeApplicationDestroyed(function () use ($hot, $temporaryPublic) {
            if (is_file($hot)) {
                unlink($hot);
            }
            if ($temporaryPublic) {
                rmdir($temporaryPublic);
            }
        });
        $vite = app(Vite::class)->useHotFile($hot);
        $entries = ['resources/js/tekora/marketplace.js', 'resources/css/tekora/marketplace.css'];
        $manifestPath = public_path('build/manifest.json');
        if (is_file($manifestPath)) {
            unlink($hot);
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $urls = [];
            foreach ($entries as $entry) {
                $this->assertArrayHasKey($entry, $manifest);
                $file = $manifest[$entry]['file'];
                $this->assertFileExists(public_path('build/'.$file));
                $urls[] = asset('build/'.$file);
            }
        } else {
            $this->assertFileDoesNotExist($manifestPath);
            file_put_contents($hot, 'http://127.0.0.1:5173');
            $urls = array_map(fn ($entry) => 'http://127.0.0.1:5173/'.$entry, $entries);
        }
        $response = $this->get('/servicios')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//script[@type="module" and @src="'.$urls[0].'"]')->length);
        $this->assertSame(1, $xpath->query('//link[@rel="stylesheet" and @href="'.$urls[1].'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@method="GET"]')->length);
    }

    public function test_preview_is_memory_only_and_native_get_query_normalization_does_not_fake_unknown_searches(): void
    {
        require_once base_path('tools/tekora-w3-preview-key.php');
        $this->assertSame('/servicios?category=diseno&page=2&q=100%25', tekoraMarketplacePreviewKey('/servicios?q=100%25&page=2&category=diseno&modality='));
        $this->assertSame('/servicios?q=0', tekoraMarketplacePreviewKey('/servicios?q=0&category=&modality='));
        $this->assertNull(tekoraMarketplacePreviewKey('/servicios?q[]=bad'));
        $target = sys_get_temp_dir().'/tekora-w3-test-'.bin2hex(random_bytes(10));
        try {
            (new Process([PHP_BINARY, base_path('tools/tekora-w3-preview.php'), $target, 'http://127.0.0.1:8051'], base_path()))->setTimeout(90)->mustRun();
            $safety = json_decode(file_get_contents($target.'/safety.json'), true);
            $this->assertSame(':memory:', $safety['database']);
            $this->assertFalse($safety['environment_file_exists']);
            $this->assertFalse($safety['environment_directory_is_repository']);
            $this->assertFalse($safety['post_backend']);
            $routes = json_decode(file_get_contents($target.'/routes.json'), true);
            $this->assertArrayHasKey('/servicios?category=diseno&modality=remote&page=2&q=Dise%C3%B1o', $routes);
            $this->assertArrayNotHasKey('/servicios?q=NOT-EXPORTED', $routes);
            foreach (json_decode(file_get_contents($target.'/queries.json'), true) as $query) {
                $this->assertSame(0, $query['write_queries']);
            }
        } finally {
            foreach (glob($target.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($target)) {
                rmdir($target);
            }
        }
    }
}
