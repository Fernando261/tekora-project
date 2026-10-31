<?php

use App\Http\Controllers\ProviderAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/disponibilidad')->name('availability.')->group(function () {
    Route::get('/', [ProviderAvailabilityController::class, 'edit'])->name('edit');
    Route::patch('/', [ProviderAvailabilityController::class, 'update'])->middleware('throttle:provider-availability')->name('update');
});
