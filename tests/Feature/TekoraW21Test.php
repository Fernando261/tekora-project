<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

#[Group('web-w21')]
class TekoraW21Test extends TestCase
{
    use DashboardFixtures;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_server_sections_are_complete_anchor_navigation_without_javascript(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $this->service($dual);
        foreach (['account.client' => ['client', ['summary', 'reservations', 'requests']], 'provider.dashboard' => ['provider', ['summary', 'activity', 'agenda', 'requests']]] as $route => [$kind, $keys]) {
            $response = $this->actingAs($dual)->get(route($route))->assertOk();
            $xpath = $this->document($response->getContent());
            $anchors = $xpath->query('//a[@data-dashboard-tab]');
            $this->assertSame(count($keys), $anchors->length);
            foreach ($keys as $index => $key) {
                $this->assertSame('#'.$kind.'-'.$key, $anchors->item($index)->getAttribute('href'));
                $this->assertSame(1, $xpath->query('//*[@id="'.$kind.'-'.$key.'"]')->length);
            }
            $this->assertSame(0, $xpath->query('//*[@data-dashboard-module and (@hidden or @inert)]')->length);
            $this->assertSame(0, $xpath->query('//*[@role="tab"]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-dashboard-panels]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-dashboard-pill and @aria-hidden="true"]')->length);
            $this->assertSame(4, $xpath->query('//*[@data-dashboard-module="summary"]//*[@data-dashboard-card]')->length);
        }
    }

    public function test_all_ten_kpi_and_bars_table_and_line_keep_the_same_period_data(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $this->completeForReview($inquiry);
        foreach ([7, 30, 90] as $days) {
            $response = $this->actingAs($provider)->get(route('provider.dashboard', ['period' => $days]))->assertOk();
            $data = $response->viewData('dashboard');
            $xpath = $this->document($response->getContent());
            $this->assertSame(10, $xpath->query('//*[@data-kpi]')->length);
            $this->assertSame(6, $xpath->query('//*[@data-dashboard-module="activity"]//*[@data-kpi]')->length);
            foreach ($data['metrics'] as $metric) {
                $nodes = $xpath->query('//*[@data-kpi="'.$metric['id'].'"]');
                $this->assertSame(1, $nodes->length);
                $text = $nodes->item(0)->textContent;
                foreach (array_filter([$metric['label'], (string) $metric['value'], $metric['note'], $metric['description']]) as $expected) {
                    $this->assertStringContainsString($expected, $text);
                }
                if ($metric['url']) {
                    $this->assertSame($metric['url'], $xpath->query('.//a', $nodes->item(0))->item(0)->getAttribute('href'));
                }
            }
            $rects = $xpath->query('//*[@data-dashboard-chart]/*[local-name()="rect"]');
            $actual = [];
            foreach ($rects as $rect) {
                $actual[] = array_map(fn ($key) => $rect->getAttribute($key), ['x', 'y', 'width', 'height']);
            }
            $this->assertSame(array_map(fn ($row) => [(string) ($row['x'] - $row['width'] / 2), (string) (150 - $row['height']), (string) $row['width'], (string) $row['height']], $data['series']), $actual);
            $cells = $xpath->query('//*[contains(@class,"dashboard-data")]//tbody/tr/td');
            $this->assertSame(array_column($data['series'], 'count'), array_map(fn ($cell) => (int) $cell->textContent, iterator_to_array($cells)));
            $points = implode(' ', array_map(fn ($row) => $row['x'].','.(150 - $row['height']), $data['series']));
            $this->assertSame($points, $xpath->query('//*[@data-dashboard-series="completed"]')->item(0)->getAttribute('points'));
            $form = $xpath->query('//form[@method="GET"]')->item(0);
            $this->assertSame(route('provider.dashboard'), $form->getAttribute('action'));
            $this->assertSame(['7', '30', '90'], array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($xpath->query('.//option', $form))));
        }
    }

    public function test_dual_client_compositions_do_not_expose_the_provider_side_or_write_on_get(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $other = $this->account(RoleName::Provider);
        $own = $this->inquiry($dual, $other, InquiryStatus::Accepted);
        $own->service->update(['title' => 'W21-MI-SOLICITUD']);
        $received = $this->inquiry(null, $dual);
        $received->service->update(['title' => 'W21-SOLO-PRESTADOR']);
        $this->message($other, $own, 'W21-CUERPO-PRIVADO');
        $this->message($received->client, $received, 'W21-OTRO-CUERPO');
        $tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
        $before = $this->reservationRows($tables);
        $response = $this->actingAs($dual)->get(route('account.client'))->assertOk()->assertSee('W21-MI-SOLICITUD')->assertDontSee('W21-SOLO-PRESTADOR')->assertDontSee('W21-CUERPO-PRIVADO')->assertDontSee('W21-OTRO-CUERPO');
        $this->assertSame(1, $response->viewData('summary')['active']);
        $this->assertSame(1, $response->viewData('summary')['unread']);
        $response->assertSee('Cuenta completa')->assertSee(route('identity.citas'), false);
        $this->get(route('provider.dashboard'))->assertOk()->assertSee('W21-SOLO-PRESTADOR');
        $this->assertSame($before, $this->reservationRows($tables));
    }

    public function test_empty_setup_access_and_errors_do_not_create_fake_tabs_or_metrics(): void
    {
        $this->get(route('account.client'))->assertRedirect(route('login'));
        $this->actingAs($this->account(RoleName::Admin))->get(route('account.client'))->assertForbidden();
        $provider = $this->account(RoleName::Provider);
        $this->actingAs($provider)->get(route('provider.dashboard'))->assertOk()->assertSee('Completa tu perfil')->assertDontSee('data-dashboard-tab=', false)->assertDontSee('data-kpi=', false);
        $provider->providerProfile()->create(['display_name' => 'Vacío W21']);
        $this->get(route('provider.dashboard'))->assertOk()->assertSee('No tienes próximas reservas confirmadas')->assertSee('No tienes solicitudes pendientes')->assertSee('Sin decisiones');
        $this->get(route('provider.dashboard', ['period' => 14]))->assertStatus(422)->assertDontSee('SQLSTATE');
        $this->actingAs($this->account(RoleName::Client))->get(route('account.client'))->assertOk()->assertSee('Tu próxima reserva está por llegar')->assertSee('Tu primera idea puede empezar hoy');
        DB::statement('DROP TABLE service_inquiry_reads');
        $this->get(route('account.client'))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('data-dashboard-tab=', false);
    }

    public function test_dashboard_assets_csp_and_native_logout_are_preserved(): void
    {
        $assets = $this->dashboardAssets();
        $user = $this->account(RoleName::Client);
        $response = $this->actingAs($user)->get(route('account.client'))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//script[not(@src)]')->length);
        $this->assertDashboardAssets($xpath, $assets);
        $form = $xpath->query('//form[contains(@class,"logout-form")]')->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(0, $xpath->query('//a[contains(@href,"/cuenta/prestador/dashboard")]')->length);
        auth('web')->logout();
        $headers = $this->get('/login')->assertOk()->assertSee('data-auth-switch')->headers;
        $this->assertStringContainsString("script-src 'self'", $headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("'unsafe-eval'", $headers->get('Content-Security-Policy'));
    }

    public function test_dashboard_assets_without_a_build_use_real_development_tags(): void
    {
        $public = sys_get_temp_dir().'/tekora-w21-public-'.bin2hex(random_bytes(8));
        mkdir($public);
        $this->beforeApplicationDestroyed(fn () => rmdir($public));
        $this->app->usePublicPath($public);
        $this->assertFileDoesNotExist(public_path('build/manifest.json'));
        $assets = $this->dashboardAssets();
        $response = $this->actingAs($this->account(RoleName::Client))->get(route('account.client'))->assertOk();
        $this->assertDashboardAssets($this->document($response->getContent()), $assets);
    }

    private function dashboardAssets(): array
    {
        $vite = app(Vite::class);
        $hot = tempnam(sys_get_temp_dir(), 'tekora-w21-hot-');
        $this->beforeApplicationDestroyed(function () use ($hot) {
            if (is_file($hot)) {
                unlink($hot);
            }
        });
        $vite->useHotFile($hot);
        $entries = ['resources/js/tekora/dashboards.js', 'resources/css/tekora/dashboards.css'];
        $manifestPath = public_path('build/manifest.json');
        if (is_file($manifestPath)) {
            // An existing public/hot must not mask the production manifest in this check.
            unlink($hot);
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $urls = [];
            foreach ($entries as $entry) {
                $this->assertArrayHasKey($entry, $manifest);
                $this->assertArrayHasKey('file', $manifest[$entry]);
                $file = $manifest[$entry]['file'];
                $this->assertIsString($file);
                $this->assertStringEndsWith('.'.pathinfo($entry, PATHINFO_EXTENSION), $file);
                $this->assertFileExists(public_path('build/'.$file));
                $urls[] = asset('build/'.$file);
            }

            return $urls;
        }
        // Exercise a genuinely absent build location without deleting the real manifest.
        $build = 'tekora-w21-no-build-'.bin2hex(random_bytes(8));
        $this->assertFileDoesNotExist(public_path($build.'/manifest.json'));
        $vite->useBuildDirectory($build);
        file_put_contents($hot, 'http://127.0.0.1:5173');

        return array_map(fn ($entry) => 'http://127.0.0.1:5173/'.$entry, $entries);
    }

    private function assertDashboardAssets(DOMXPath $xpath, array $assets): void
    {
        $scripts = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//script[@type="module"]/@src')));
        $styles = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//link[@rel="stylesheet"]/@href')));
        $this->assertContains($assets[0], $scripts, 'Falta el módulo JS del dashboard.');
        $this->assertContains($assets[1], $styles, 'Falta la hoja CSS del dashboard.');
    }

    public function test_historical_mindbloom_keeps_its_layout_outside_the_dashboard(): void
    {
        $this->actingAs($this->account(RoleName::Client));
        foreach ([route('home'), '/perfil', '/comunidad', '/citas', '/conferencias'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('data-dashboard-navigation')->assertDontSee('tk-dashboard-atmosphere')->assertDontSee('resources/js/tekora/dashboards.js');
        }
    }

    public function test_preview_bootstraps_without_reading_a_repository_environment_or_database(): void
    {
        $target = sys_get_temp_dir().'/tekora-w21-test-'.bin2hex(random_bytes(10));
        try {
            $process = new Process([PHP_BINARY, base_path('tools/tekora-w2-preview.php'), $target, 'http://127.0.0.1:8017'], base_path());
            $process->setTimeout(60)->mustRun();
            $safety = json_decode(file_get_contents($target.'/safety.json'), true);
            $this->assertSame(['environment' => 'testing', 'connection' => 'sqlite', 'database' => ':memory:', 'environment_file_exists' => false, 'environment_directory_is_repository' => false, 'post_backend' => false], $safety);
            $routes = json_decode(file_get_contents($target.'/routes.json'), true);
            $this->assertArrayHasKey('/cuenta/cliente', $routes);
            $this->assertArrayHasKey('/cuenta/prestador/dashboard?period=90', $routes);
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
