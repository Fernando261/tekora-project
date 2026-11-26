<?php

namespace Tests\Feature;

use App\Payments\CommercialAmount;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommercialApiContractTest extends TestCase
{
    public function test_routes_documentation_examples_and_payment_disabled_contract_agree(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $operations = [
            'quotes.index' => ['GET', '/inquiries/{inquiry}/quotes'],
            'quotes.issue' => ['POST', '/inquiries/{inquiry}/quotes'],
            'quotes.show' => ['GET', '/quotes/{quote}'],
            'quotes.accept' => ['POST', '/quotes/{quote}/accept'],
            'quotes.acceptance' => ['GET', '/quotes/{quote}/acceptance'],
            'reservations.checkout-context' => ['GET', '/reservations/{reservation}/checkout-context'],
            'reservations.checkout.show' => ['GET', '/reservations/{reservation}/checkout'],
            'reservations.checkout.submit' => ['POST', '/reservations/{reservation}/checkout'],
        ];
        foreach ($operations as $name => [$method, $path]) {
            $route = Route::getRoutes()->getByName('api.v1.'.$name);
            $this->assertNotNull($route);
            $this->assertContains($method, $route->methods());
            $this->assertSame('api/v1'.$path, $route->uri());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains(\App\Http\Middleware\MobileClient::class, $route->gatherMiddleware());
            $this->assertSame([['bearerAuth' => []]], $spec['paths'][$path][strtolower($method)]['security']);
        }
        $context = $spec['components']['examples']['B3CheckoutContext']['value']['data'];
        $this->assertFalse($context['future_payment']['can_start']);
        $this->assertFalse($context['future_payment']['enabled']);
        $this->assertFalse($context['confirmation_policy']['requires_verified_payment']);
        $this->assertSame(900, $context['hold']['hold_seconds']);
        $amount = CommercialAmount::fromCents($context['amount']['total_cents']);
        $this->assertSame($amount->commissionCents, $context['amount']['commission_cents']);
        $this->assertSame($amount->providerCents, $context['amount']['provider_cents']);
        $this->assertSame(900, strtotime($context['hold']['expires_at']) - strtotime($context['hold']['accepted_at']));
        $this->assertSame(false, $spec['components']['schemas']['CheckoutContext']['properties']['future_payment']['properties']['can_start']['const']);
        $walk = function (array $node) use (&$walk, $spec) {
            if (isset($node['$ref'])) {
                $this->assertStringStartsWith('#/', $node['$ref']);
                $target = $spec;
                foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                    $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                    $this->assertArrayHasKey($part, $target);
                    $target = $target[$part];
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($spec);
    }

    public function test_web_context_is_private_json_even_without_accept_and_has_no_mutation_route(): void
    {
        $path = '/cuenta/reservas/999/contexto-comercial';
        $this->get($path)->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'unauthenticated');
        $this->post($path)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
        $this->get('/api/v1/demo/checkout')->assertNotFound();
    }
}
