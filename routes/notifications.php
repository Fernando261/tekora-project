<?php

use App\Http\Controllers\InquiryNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->prefix('cuenta/notificaciones')->name('notifications.')->group(function () {
    Route::get('/', [InquiryNotificationController::class, 'index'])->name('index');
    Route::post('/actividad/{notification}/leer', [InquiryNotificationController::class, 'readActivity'])
        ->whereNumber('notification')->middleware('throttle:activity-notification-reads')->name('activity.read');
    Route::post('/{notification}/leer', [InquiryNotificationController::class, 'read'])
        ->whereNumber('notification')->name('read');
});
