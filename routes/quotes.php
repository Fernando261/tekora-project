<?php

use App\Http\Controllers\WebPricingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'throttle:60,1'])->group(function () {
    Route::get('/cuenta/reservas/{reservation}/contexto-comercial', \App\Http\Controllers\CommercialContextController::class)->whereNumber('reservation')->middleware(\App\Http\Middleware\ApiHeaders::class)->name('reservations.checkout-context');
    Route::post('/cuenta/servicios/{service}/precio', [WebPricingController::class, 'publish'])->whereNumber('service')->name('provider-services.price');
    Route::post('/cuenta/solicitudes/{inquiry}/cotizaciones', [WebPricingController::class, 'issue'])->whereNumber('inquiry')->name('quotes.issue');
    Route::get('/cuenta/cotizaciones/{quote}', [WebPricingController::class, 'show'])->whereUuid('quote')->name('quotes.show');
    Route::post('/cuenta/cotizaciones/{quote}/aceptar', [WebPricingController::class, 'accept'])->whereUuid('quote')->name('quotes.accept');
});
