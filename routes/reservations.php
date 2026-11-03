<?php

use App\Http\Controllers\ServiceReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/reservas')->name('reservations.')->group(function () {
    Route::get('/', [ServiceReservationController::class, 'index'])->name('index');
    Route::get('/solicitudes/{inquiry}/crear', [ServiceReservationController::class, 'create'])->whereNumber('inquiry')->name('create');
    Route::post('/solicitudes/{inquiry}', [ServiceReservationController::class, 'store'])->whereNumber('inquiry')
        ->middleware('throttle:reservation-proposals')->name('store');
    Route::get('/{reservation}', [ServiceReservationController::class, 'show'])->whereNumber('reservation')->name('show');
    foreach (['confirmar' => 'confirm', 'rechazar' => 'reject', 'cancelar' => 'cancel', 'completar' => 'complete'] as $path => $decision) {
        Route::patch('/{reservation}/'.$path, [ServiceReservationController::class, 'decide'])->whereNumber('reservation')
            ->defaults('decision', $decision)->middleware('throttle:reservation-decisions')->name($decision);
    }
});
