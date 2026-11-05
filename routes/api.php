<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Middleware\MobileClient;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('auth.login');
    Route::middleware(['auth:sanctum', MobileClient::class])->group(function () {
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
