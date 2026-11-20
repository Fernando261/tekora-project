<?php

namespace Tests\Feature;

use App\Http\Middleware\MobileClient;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * M2.0 deliberately ships no mobile payment write endpoint.
 * Keep this boundary while the B3.2 checkout stays a local web-only fixture.
 */
class MobileCheckoutHandoffContractTest extends TestCase
{
    public function test_mobile_checkout_contract_is_read_only_and_requires_sanctum_client_auth(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.reservations.checkout-context');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/reservations/{reservation}/checkout-context', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());
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
}
