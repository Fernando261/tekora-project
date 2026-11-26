<?php

namespace Tests\Support;

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutContextController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\IntegratedCheckoutController;
use App\Http\Controllers\Api\V1\NotificationsController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Controllers\Api\V1\ReviewsController;
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
        $extensions = $this->mobileClientExtensions();
        ksort($extensions);
        $this->assertSame($extensions, array_intersect_key($actual, $extensions));
        $actual = array_diff_key($actual, $extensions);
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

    protected function m28ApiInventory(): array
    {
        $routes = [];
        foreach ([
            'register' => ['register', 'register', 'request'],
            'register.confirm' => ['register/confirm', 'confirm', 'confirm'],
            'forgot-password' => ['forgot-password', 'forgotPassword', 'request'],
            'reset-password' => ['reset-password', 'resetPassword', 'confirm'],
        ] as $name => [$uri, $action, $limit]) {
            $routes['api.v1.auth.'.$name] = ['uri' => 'api/v1/auth/'.$uri, 'methods' => ['POST'],
                'action' => IdentityController::class.'@'.$action, 'middleware' => ['api', 'throttle:mobile-identity-'.$limit], 'wheres' => []];
        }
        foreach ([
            'show' => ['profile', 'show', 'GET'],
            'update' => ['profile', 'update', 'PATCH'],
            'photo.show' => ['photo', 'photo', 'GET'],
            'photo.store' => ['photo', 'storePhoto', 'POST'],
        ] as $name => [$uri, $action, $method]) {
            $middleware = ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'];
            if ($method !== 'GET') {
                $middleware[] = 'throttle:mobile-profile-writes';
            }
            $routes['api.v1.profile.'.$name] = ['uri' => 'api/v1/me/'.$uri, 'methods' => $method === 'GET' ? ['GET', 'HEAD'] : [$method],
                'action' => ProfileController::class.'@'.$action, 'middleware' => $middleware, 'wheres' => []];
        }
        ksort($routes);

        return $routes;
    }

    protected function mobileClientExtensions(): array
    {
        return $this->m28ApiInventory() + $this->m29ApiInventory() + $this->m30ApiInventory() + $this->m31ApiInventory() + $this->v810ApiInventory() + [
            'api.v1.municipalities.index' => [
                'uri' => 'api/v1/municipalities', 'methods' => ['GET', 'HEAD'], 'action' => CatalogController::class.'@municipalities',
                'middleware' => ['api', 'throttle:mobile-catalog'], 'wheres' => [],
            ],
        ];
    }

    /** The B3.2 sandbox routes are private, synthetic and fixture-gated at runtime. */
    protected function m31ApiInventory(): array
    {
        $base = ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'];
        $uri = 'api/v1/reservations/{reservation}/demo-checkout';

        return [
            'api.v1.reservations.demo-checkout.show' => ['uri' => $uri, 'methods' => ['GET', 'HEAD'],
                'action' => \App\Http\Controllers\Api\V1\SyntheticCheckoutController::class.'@show',
                'middleware' => $base, 'wheres' => ['reservation' => '[0-9]+']],
            'api.v1.reservations.demo-checkout.submit' => ['uri' => $uri, 'methods' => ['POST'],
                'action' => \App\Http\Controllers\Api\V1\SyntheticCheckoutController::class.'@submit',
                'middleware' => [...$base, 'throttle:mobile-reservation-writes'], 'wheres' => ['reservation' => '[0-9]+']],
        ];
    }

    protected function v810ApiInventory(): array
    {
        $base = ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'];
        $uri = 'api/v1/reservations/{reservation}/checkout';

        return [
            'api.v1.reservations.checkout.show' => ['uri' => $uri, 'methods' => ['GET', 'HEAD'],
                'action' => IntegratedCheckoutController::class.'@show',
                'middleware' => $base, 'wheres' => ['reservation' => '[0-9]+']],
            'api.v1.reservations.checkout.submit' => ['uri' => $uri, 'methods' => ['POST'],
                'action' => IntegratedCheckoutController::class.'@submit',
                'middleware' => [...$base, 'throttle:mobile-reservation-writes'], 'wheres' => ['reservation' => '[0-9]+']],
        ];
    }

    protected function m30ApiInventory(): array
    {
        return [
            'api.v1.categories.index' => ['uri' => 'api/v1/categories', 'methods' => ['GET', 'HEAD'],
                'action' => CatalogController::class.'@categories', 'middleware' => ['api', 'throttle:mobile-catalog'], 'wheres' => []],
            'api.v1.providers.services.index' => ['uri' => 'api/v1/providers/{provider}/services', 'methods' => ['GET', 'HEAD'],
                'action' => CatalogController::class.'@providerServices', 'middleware' => ['api', 'throttle:mobile-catalog'], 'wheres' => ['provider' => '[0-9]+']],
            'api.v1.dashboard.show' => ['uri' => 'api/v1/me/dashboard', 'methods' => ['GET', 'HEAD'],
                'action' => DashboardController::class, 'middleware' => ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'], 'wheres' => []],
        ];
    }

    protected function m29ApiInventory(): array
    {
        $routes = [];
        foreach ([
            'services.reviews.index' => ['GET', 'services/{service}/reviews', 'forService', 'service', true],
            'providers.reviews.index' => ['GET', 'providers/{provider}/reviews', 'forProvider', 'provider', true],
            'reviews.index' => ['GET', 'reviews', 'index', null, false],
            'reviews.show' => ['GET', 'reviews/{review}', 'show', 'review', false],
            'reviews.store' => ['POST', 'reservations/{reservation}/review', 'store', 'reservation', false],
            'reviews.withdraw' => ['PATCH', 'reviews/{review}/withdraw', 'withdraw', 'review', false],
        ] as $name => [$method, $uri, $action, $parameter, $public]) {
            $middleware = $public ? ['api', 'throttle:mobile-catalog'] : ['api', 'auth:sanctum', MobileClient::class, 'throttle:mobile-private'];
            if ($method !== 'GET') {
                $middleware[] = 'throttle:mobile-review-writes';
            }
            $routes['api.v1.'.$name] = ['uri' => 'api/v1/'.$uri, 'methods' => $method === 'GET' ? ['GET', 'HEAD'] : [$method],
                'action' => ReviewsController::class.'@'.$action, 'middleware' => $middleware, 'wheres' => $parameter === null ? [] : [$parameter => '[0-9]+']];
        }

        return $routes;
    }
}
