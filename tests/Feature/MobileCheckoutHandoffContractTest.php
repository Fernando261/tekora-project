<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Http\Middleware\MobileClient;
use App\Payments\ServicePricing;
use App\Services\ReservationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

/**
 * M2.0 deliberately ships no mobile payment write endpoint.
 * Keep this boundary while the B3.2 checkout stays a local web-only fixture.
 */
class MobileCheckoutHandoffContractTest extends TestCase
{
    use SlotHoldFixtures;

    public function test_mobile_checkout_contract_is_read_only_and_requires_sanctum_client_auth(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.reservations.checkout-context');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/reservations/{reservation}/checkout-context', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains(MobileClient::class, $route->gatherMiddleware());
        $this->assertContains('throttle:mobile-private', $route->gatherMiddleware());

        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $spec['paths']['/reservations/{reservation}/checkout-context'];

        $this->assertArrayHasKey('get', $path);
        $this->assertSame(['get'], array_keys($path));
        $this->assertSame([['bearerAuth' => []]], $path['get']['security']);
        $this->assertFalse($spec['components']['schemas']['CheckoutContext']['properties']['future_payment']['properties']['enabled']['const']);
        $this->assertFalse($spec['components']['schemas']['CheckoutContext']['properties']['future_payment']['properties']['can_start']['const']);
        $this->assertFalse($spec['components']['schemas']['ContractingJourney']['properties']['payments']['properties']['enabled']['enum'][0]);
        $this->assertSame(900, $spec['components']['examples']['B3CheckoutContext']['value']['data']['hold']['hold_seconds']);
    }

    public function test_no_mobile_payment_or_cash_write_route_is_registered(): void
    {
        // The exact inventory also detects aliases without payment-related words.
        $this->assertCurrentApiInventory();

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            if (! preg_match('~(?:checkout|payment|cash|cobro)~i', $route->uri())) {
                continue;
            }

            $this->assertSame([], array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()),
                'Mobile payment write unexpectedly exposed: '.$route->uri());
        }

        $checkoutPage = Route::getRoutes()->getByName('checkout.local.show');
        $checkoutSubmit = Route::getRoutes()->getByName('checkout.local.submit');
        $this->assertNotNull($checkoutPage);
        $this->assertNotNull($checkoutSubmit);
        $this->assertFalse(str_starts_with($checkoutPage->uri(), 'api/'));
        $this->assertFalse(str_starts_with($checkoutSubmit->uri(), 'api/'));
        $this->assertContains('POST', $checkoutSubmit->methods());
    }

    public function test_existing_mobile_reservation_commit_is_not_a_payment_operation(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.reservations.commit');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/reservations/{reservation}/commit', $route->uri());
        $this->assertContains('PATCH', $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains(MobileClient::class, $route->gatherMiddleware());

        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $commit = $spec['paths']['/reservations/{reservation}/commit']['patch'];
        $this->assertSame('commitMyHeldReservation', $commit['operationId']);
        $this->assertSame('#/components/schemas/CommitReservationInput',
            $commit['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame(['version', 'idempotency_key'],
            $spec['components']['schemas']['CommitReservationInput']['required']);
    }

    public function test_handoff_reads_require_a_real_client_token_and_hide_foreign_resources(): void
    {
        [$client, $provider, $inquiry, , $reservation] = $this->pricedProposal();
        $urls = ['/api/v1/inquiries/'.$inquiry->id.'/journey',
            '/api/v1/reservations/'.$reservation->id.'/checkout-context'];
        $other = $this->account(RoleName::Client, RoleName::Provider);

        foreach ($urls as $url) {
            Auth::forgetGuards();
            $this->getJson($url)->assertUnauthorized()->assertJsonMissingPath('data');
            $this->actingAs($client, 'web')->getJson($url)->assertUnauthorized();
            foreach ([['integration', ['client:read']], ['mobile:wrong', ['provider:write']]] as [$name, $abilities]) {
                $token = $client->createToken($name, $abilities, now()->addDay())->plainTextToken;
                Auth::forgetGuards();
                $this->getJson($url, ['Authorization' => 'Bearer '.$token])->assertForbidden();
            }
            foreach ([$provider, $other] as $actor) {
                $this->getJson($url, $this->bearer($actor))->assertNotFound()->assertJsonMissingPath('data');
            }
        }
    }

    public function test_checkout_reads_do_not_mutate_commercial_state_or_accept_payment_parameters(): void
    {
        [$client, $provider, $inquiry, , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $before = $this->commercialSnapshot();
        $url = '/api/v1/reservations/'.$reservation->id.'/checkout-context';

        for ($i = 0; $i < 2; $i++) {
            $response = $this->getJson($url, $this->bearer($client))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertJsonPath('data.future_payment.enabled', false)->assertJsonPath('data.future_payment.can_start', false)
                ->assertJsonPath('data.amount.total_cents', 100000)->assertJsonPath('data.amount.commission_cents', 15000)
                ->assertJsonPath('data.amount.provider_cents', 85000)->assertJsonPath('data.hold.hold_seconds', 900);
            foreach ([$client->email, $provider->email, 'idempotency_key', 'access_token', 'card_number', 'cvv'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))
                ->assertOk()->assertJsonPath('data.payments.enabled', false);
        }
        $this->getJson($url.'?payment_verified=true&amount_cents=1', $this->bearer($client))->assertUnprocessable();
        $this->postJson($url, [], $this->bearer($client))->assertStatus(405);
        $this->postJson('/api/v1/payments', [], $this->bearer($client))->assertNotFound();
        $this->assertSame($before, $this->commercialSnapshot());
        $this->assertHoldIntegrity();
    }

    public static function syntheticMethods(): array
    {
        return ['card' => ['card'], 'cash' => ['cash']];
    }

    #[DataProvider('syntheticMethods')]
    public function test_mobile_commit_cannot_bypass_the_local_fixture_choice_and_replays_only_b2(string $method): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        // Only this new temporary SQLite receives the synthetic table. Never a migration.
        DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY, client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL, quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card','cash')), scenario TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
            idempotency_key TEXT NOT NULL, reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL, received_at TEXT NULL
        )");
        $dir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($dir, 0700));
        $database = $dir.DIRECTORY_SEPARATOR.'tekora.sqlite';
        try {
            $pdo = DB::connection()->getPdo();
            $pdo->exec('VACUUM main INTO '.$pdo->quote($database));
            $pdo = null;
            file_put_contents($dir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "M2 TEST ONLY\n");
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $database]);
            // The preceding domain setup used memory SQLite. Begin a new request
            // scope instead of reusing its intentionally disconnected PricingStore.
            $this->app->forgetScopedInstances();
            $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
            $request = Request::create('http://localhost/', server: ['REMOTE_ADDR' => '127.0.0.1']);
            // Prove the local guard really is active; otherwise this test could pass outside B3.2.
            $this->assertTrue(LocalIntegratedCheckoutController::permitsSyntheticRequest($request));
            $input = $this->holdInput(['version' => $reservation->fresh()->version]);
            $url = '/api/v1/reservations/'.$reservation->id.'/commit';
            $tables = ['synthetic_checkout_attempts'];
            $before = $this->commercialSnapshot($tables);
            $this->patchJson($url, $input, $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
            $this->assertSame($before, $this->commercialSnapshot($tables));

            // A bearer alone cannot write to the existing web fixture.
            $this->postJson(route('checkout.local.submit', $reservation), ['method' => $method], $this->bearer($client))->assertUnauthorized();
            $this->assertSame($before, $this->commercialSnapshot($tables));
            $choice = ['method' => $method, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
            if ($method === 'card') {
                $choice += ['card_number' => '4242424242424242', 'expiry' => '12/30', 'cvv' => '123'];
            }
            $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation), $choice)->assertRedirect();
            $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
            $this->assertDatabaseCount('synthetic_checkout_attempts', 1);
            $attempt = DB::table('synthetic_checkout_attempts')->get()->toArray();
            $this->assertSame($method === 'card' ? 'test_approved' : 'awaiting_cash', $attempt[0]->status);
            $this->assertNull($attempt[0]->received_at);

            $this->patchJson($url, $input, $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'confirmed');
            $confirmed = $this->commercialSnapshot($tables);
            $this->patchJson($url, $input, $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'confirmed');
            $this->assertSame($confirmed, $this->commercialSnapshot($tables));
            $this->assertEquals($attempt, DB::table('synthetic_checkout_attempts')->get()->toArray());
            $this->assertHoldIntegrity();
        } finally {
            // Release all PDO references before removing only the files owned by this test.
            $pdo = null;
            Auth::forgetGuards();
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => ':memory:']);
            foreach (['tekora.sqlite', 'tekora.sqlite-journal', 'tekora.sqlite-wal', 'tekora.sqlite-shm', '.tekora-b32-synthetic'] as $file) {
                if (is_file($dir.DIRECTORY_SEPARATOR.$file)) {
                    unlink($dir.DIRECTORY_SEPARATOR.$file);
                }
            }
            rmdir($dir);
        }
    }

    private function commercialSnapshot(array $extra = []): array
    {
        return $this->reservationRows(['service_inquiries', 'service_quotes', 'quote_acceptances',
            'service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events',
            'reservation_hold_clock', 'reservation_action_receipts', 'reservation_occupancy', ...$extra]);
    }

    public function test_importable_http_examples_execute_against_the_existing_api_in_a_non_demo_environment(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, [
            'amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $quote = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->bearer($client))->assertOk()->json('data.id');
        $headers = $this->bearer($client);
        $variables = ['baseUrl' => '/api/v1', 'token' => substr($headers['Authorization'], 7),
            'inquiryId' => $inquiry->id, 'quoteId' => $quote,
            'date' => '2030-01-07', 'time' => '09:00',
            'proposalKey' => (string) \Illuminate\Support\Str::uuid(), 'commitKey' => (string) \Illuminate\Support\Str::uuid()];
        $source = str_replace("\r\n", "\n", file_get_contents(base_path('docs/api/examples/M2.0-mobile-handoff.http')));
        preg_match_all('/^(GET|POST|PATCH) ([^\n]+)\n(.*?)(?=^###|\z)/ms', $source, $examples, PREG_SET_ORDER);
        $this->assertCount(9, $examples);
        $this->assertFalse(LocalIntegratedCheckoutController::permitsSyntheticRequest(Request::create('http://localhost/')));

        foreach ($examples as $index => [, $method, $uri, $payload]) {
            $expand = fn ($text) => preg_replace_callback('/\{\{(\w+)\}\}/', function ($match) use (&$variables) {
                $this->assertArrayHasKey($match[1], $variables);

                return (string) $variables[$match[1]];
            }, $text);
            $payload = trim(preg_replace('/^#.*\n?/m', '', $payload));
            [$headerLines, $body] = array_pad(explode("\n\n", $payload, 2), 2, '');
            $requestHeaders = [];
            foreach (explode("\n", $headerLines) as $line) {
                [$name, $value] = explode(':', $line, 2);
                $requestHeaders[$name] = trim($expand($value));
            }
            Auth::forgetGuards();
            $response = $this->json($method, $expand($uri),
                $body === '' ? [] : json_decode($expand($body), true, flags: JSON_THROW_ON_ERROR), $requestHeaders)
                ->assertStatus($index === 5 ? 201 : 200);
            if ($index === 4) {
                $variables['availabilityRevision'] = $response->json('data.availability_revision');
            } elseif ($index === 5) {
                $variables['reservationId'] = $response->json('data.id');
                // This decision still belongs to the provider's web/domain workflow.
                app(ReservationWorkflow::class)->decide($provider, $variables['reservationId'], 'confirm', $this->holdInput());
            } elseif ($index === 6) {
                $variables['version'] = $response->json('data.version');
                $response->assertJsonPath('data.status', 'held');
            } elseif ($index === 7) {
                $response->assertJsonPath('data.future_payment.can_start', false)->assertJsonPath('data.hold.hold_seconds', 900);
            } elseif ($index === 8) {
                $response->assertJsonPath('data.status', 'confirmed');
            }
        }
        $this->assertHoldIntegrity();
    }
}
