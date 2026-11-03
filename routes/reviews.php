<?php

use App\Http\Controllers\ServiceReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/resenas')->name('reviews.')->group(function () {
    Route::get('/', [ServiceReviewController::class, 'index'])->name('index');
    Route::get('/reservas/{reservation}/crear', [ServiceReviewController::class, 'create'])->whereNumber('reservation')->name('create');
    Route::post('/reservas/{reservation}', [ServiceReviewController::class, 'store'])->whereNumber('reservation')->middleware('throttle:service-reviews')->name('store');
    Route::get('/{review}', [ServiceReviewController::class, 'show'])->whereNumber('review')->name('show');
    Route::patch('/{review}/retirar', [ServiceReviewController::class, 'withdraw'])->whereNumber('review')->middleware('throttle:service-reviews')->name('withdraw');
});
