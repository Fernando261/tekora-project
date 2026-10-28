<?php

use App\Http\Controllers\ProviderServiceController;
use App\Http\Controllers\PublicProviderController;
use App\Http\Controllers\ServiceCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/servicios', [ServiceCatalogController::class, 'index'])->name('catalog.index');
Route::get('/servicios/{service}', [ServiceCatalogController::class, 'show'])->whereNumber('service')->name('catalog.show');
Route::get('/prestadores', [PublicProviderController::class, 'index'])->name('public-providers.index');
Route::get('/prestadores/{providerProfile}', [PublicProviderController::class, 'show'])->whereNumber('providerProfile')->name('public-providers.show');

Route::middleware('auth:web')->prefix('cuenta/servicios')->name('provider-services.')->group(function () {
    Route::get('/', [ProviderServiceController::class, 'index'])->name('index');
    Route::get('/crear', [ProviderServiceController::class, 'create'])->name('create');
    Route::post('/', [ProviderServiceController::class, 'store'])->name('store');
    Route::get('/{service}/editar', [ProviderServiceController::class, 'edit'])->whereNumber('service')->name('edit');
    Route::put('/{service}', [ProviderServiceController::class, 'update'])->whereNumber('service')->name('update');
    Route::patch('/{service}/estado', [ProviderServiceController::class, 'status'])->whereNumber('service')->name('status');
});
