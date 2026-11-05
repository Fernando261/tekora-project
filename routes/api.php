<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Middleware\MobileClient;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('auth.login');
    Route::middleware(['auth:sanctum', MobileClient::class])->group(function () {
        Route::middleware('throttle:mobile-private')->group(function () {
            $operations = [
                ['get', 'inquiries', 'inquiries', 'inquiries.index', null],
                ['post', 'services/{service}/inquiries', 'open', 'inquiries.store', 'mobile-inquiry-writes'],
                ['get', 'inquiries/{inquiry}', 'inquiry', 'inquiries.show', null],
                ['patch', 'inquiries/{inquiry}/cancel', 'cancelInquiry', 'inquiries.cancel', 'mobile-inquiry-writes'],
                ['get', 'conversations', 'conversations', 'conversations.index', null],
                ['get', 'inquiries/{inquiry}/messages', 'messages', 'messages.index', null],
                ['post', 'inquiries/{inquiry}/messages', 'send', 'messages.store', 'mobile-message-writes'],
                ['post', 'inquiries/{inquiry}/messages/read', 'read', 'messages.read', 'mobile-read-writes'],
                ['get', 'inquiries/{inquiry}/reservation-options', 'options', 'reservations.options', null],
                ['post', 'inquiries/{inquiry}/reservations', 'propose', 'reservations.store', 'mobile-reservation-writes'],
                ['get', 'reservations', 'reservations', 'reservations.index', null],
                ['get', 'reservations/{reservation}', 'reservation', 'reservations.show', null],
                ['patch', 'reservations/{reservation}/cancel', 'cancelReservation', 'reservations.cancel', 'mobile-reservation-writes'],
            ];
            foreach ($operations as [$method, $uri, $action, $name, $limiter]) {
                $route = Route::$method($uri, [OperationsController::class, $action])->name($name)
                    ->where(['inquiry' => '[0-9]+', 'reservation' => '[0-9]+', 'service' => '[0-9]+']);
                if ($limiter) {
                    $route->middleware('throttle:'.$limiter);
                }
            }
        });
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
    Route::middleware('throttle:mobile-catalog')->group(function () {
        Route::get('services', [CatalogController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [CatalogController::class, 'show'])->whereNumber('service')->name('services.show');
        Route::get('services/{service}/availability', [CatalogController::class, 'availability'])->whereNumber('service')->name('services.availability');
        Route::get('providers', [CatalogController::class, 'providersIndex'])->name('providers.index');
        Route::get('providers/{provider}', [CatalogController::class, 'provider'])->whereNumber('provider')->name('providers.show');
    });
});
