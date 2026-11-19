<?php

namespace Tests\Support;

use App\Http\Controllers\Api\V1\CheckoutContextController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Middleware\MobileClient;

trait CommercialApiInventoryAssertions
{
    protected function assertCurrentApiInventory(): void
    {
        $baseline = json_decode(file_get_contents(base_path('tests/Fixtures/b3-legacy-api-routes.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('043531d6d153f64d5fc0e7cc6afc6a350af33563', $baseline['base_sha']);
        $this->assertCount(22, $baseline['routes']);
        $approved = [
            'api.v1.inquiries.journey' => ['GET', 'api/v1/inquiries/{inquiry}/journey', \App\Http\Controllers\Api\V1\ContractingJourneyController::class, 'inquiry'],
            'api.v1.quotes.index' => ['GET', 'api/v1/inquiries/{inquiry}/quotes', QuotesController::class.'@index', 'inquiry'],
            'api.v1.quotes.issue' => ['POST', 'api/v1/inquiries/{inquiry}/quotes', QuotesController::class.'@issue', 'inquiry'],
            'api.v1.quotes.show' => ['GET', 'api/v1/quotes/{quote}', QuotesController::class.'@show', 'quote'],
            'api.v1.quotes.accept' => ['POST', 'api/v1/quotes/{quote}/accept', QuotesController::class.'@accept', 'quote'],
            'api.v1.quotes.acceptance' => ['GET', 'api/v1/quotes/{quote}/acceptance', QuotesController::class.'@acceptance', 'quote'],
            'api.v1.reservations.checkout-context' => ['GET', 'api/v1/reservations/{reservation}/checkout-context', CheckoutContextController::class, 'reservation'],
            'api.v1.notifications.index' => ['GET', 'api/v1/notifications', NotificationsController::class.'@index', null],
            'api.v1.notifications.unread-count' => ['GET', 'api/v1/notifications/unread-count', NotificationsController::class.'@unreadCount', null],
            'api.v1.notifications.read' => ['POST', 'api/v1/notifications/{source_type}/{id}/read', NotificationsController::class.'@read', 'source_type'],
        ];
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $name = $route->getName() ?? 'unnamed:'.$route->uri();
            $this->assertArrayNotHasKey($name, $actual);
            $actual[$name] = ['uri' => $route->uri(), 'methods' => $route->methods(), 'action' => $route->getActionName(),
                'middleware' => $route->gatherMiddleware(), 'wheres' => $route->wheres];
        }
        ksort($actual);
        // Preserve every original method, URI, action, middleware and route constraint.
        $this->assertSame($baseline['routes'], array_diff_key($actual, $approved));
        $this->assertCount(32, $actual);
        foreach ($approved as $name => [$method, $uri, $action, $parameter]) {
            $this->assertArrayHasKey($name, $actual);
            $row = $actual[$name];
            $this->assertSame($uri, $row['uri']);
            $this->assertSame($method === 'GET' ? ['GET', 'HEAD'] : ['POST'], $row['methods']);
            $this->assertSame($action, $row['action']);
            $middleware = ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'];
            if ($method === 'POST') {
                $middleware[] = $name === 'api.v1.notifications.read' ? 'throttle:mobile-read-writes' : 'throttle:mobile-quote-writes';
            }
            $this->assertSame($middleware, $row['middleware']);
            if ($parameter !== null) {
                $this->assertArrayHasKey($parameter, $row['wheres']);
            }
        }
    }
}
