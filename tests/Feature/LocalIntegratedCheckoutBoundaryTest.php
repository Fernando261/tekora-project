<?php

namespace Tests\Feature;

use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Http\Middleware\CheckoutDemoOnly;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocalIntegratedCheckoutBoundaryTest extends TestCase
{
    public function test_routes_require_authentication_and_do_not_reuse_legacy_demo(): void
    {
        $this->get('/cuenta/reservas/7/checkout')->assertRedirect(route('login'));
        $this->assertTrue(class_exists(LocalIntegratedCheckoutController::class));
        $this->assertSame('/cuenta/reservas/7/checkout', route('checkout.local.show', 7, false));
        $this->assertNotSame(route('demo.checkout'), route('checkout.local.show', 7));
    }

    public function test_local_boundary_rejects_external_hosts_and_remote_addresses(): void
    {
        $this->app->instance('env', 'testing');
        $this->assertFalse(CheckoutDemoOnly::permitsLocalRequest(Request::create('https://tekora.example.test/demo/checkout')));
        $this->assertFalse(CheckoutDemoOnly::permitsLocalRequest(Request::create('http://localhost/demo/checkout', server: ['REMOTE_ADDR' => '203.0.113.5'])));
    }

    public function test_route_rejects_unsupported_database_engines_before_accessing_commercial_context(): void
    {
        config(['database.default' => 'mysql']);
        $this->actingAs(\App\Models\User::factory()->make(['id' => 999]), 'web')
            ->get('/cuenta/reservas/7/checkout')->assertNotFound();
    }
}
