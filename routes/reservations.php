<?php

use App\Http\Controllers\ServiceReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/reservas')->name('reservations.')->group(function () {
    Route::get('/', [ServiceReservationController::class, 'index'])->name('index');
    Route::get('/solicitudes/{inquiry}/crear', [ServiceReservationController::class, 'create'])->whereNumber('inquiry')->name('create');
    Route::post('/solicitudes/{inquiry}', [ServiceReservationController::class, 'store'])->whereNumber('inquiry')
        ->middleware('throttle:reservation-proposals')->name('store');
    Route::get('/{reservation}', [ServiceReservationController::class, 'show'])->whereNumber('reservation')->name('show');
    foreach (['confirmar' => 'confirm', 'confirmar-retencion' => 'commit', 'rechazar' => 'reject', 'cancelar' => 'cancel', 'completar' => 'complete'] as $path => $decision) {
        Route::patch('/{reservation}/'.$path, [ServiceReservationController::class, 'decide'])->whereNumber('reservation')
            ->defaults('decision', $decision)->middleware('throttle:reservation-decisions')->name($decision);
    }
});


// B3.2: reservation-linked checkout; local loopback + disposable synthetic SQLite only.
Route::middleware(['auth:web', \App\Http\Middleware\TekoraPageHeaders::class])
    ->prefix('cuenta/reservas/{reservation}/checkout')->where(['reservation' => '[0-9]+'])->name('checkout.local.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\LocalIntegratedCheckoutController::class, 'show'])->name('show');
        Route::post('/', [\App\Http\Controllers\LocalIntegratedCheckoutController::class, 'submit'])->middleware('throttle:reservation-decisions')->name('submit');
        Route::get('/efectivo', [\App\Http\Controllers\LocalIntegratedCheckoutController::class, 'cash'])->name('cash');
        Route::post('/efectivo/recibir', [\App\Http\Controllers\LocalIntegratedCheckoutController::class, 'receiveCash'])
            ->middleware('throttle:reservation-decisions')->name('cash.receive');
    });
