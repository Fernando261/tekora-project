<?php

use App\Http\Controllers\InquiryNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/notificaciones')->name('notifications.')->group(function () {
    Route::get('/', [InquiryNotificationController::class, 'index'])->name('index');
    Route::post('/{notification}/leer', [InquiryNotificationController::class, 'read'])
        ->whereNumber('notification')->name('read');
});
