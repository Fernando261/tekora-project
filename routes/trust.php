<?php

use App\Enums\Permission;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ProfessionalCredentialController;
use App\Http\Middleware\TekoraPageHeaders;
use Illuminate\Support\Facades\Route;

Route::get('/legal/{policy}', [LegalController::class, 'show'])->middleware(TekoraPageHeaders::class)->name('legal.show');
Route::post('/legal/preferencias-cookies', [LegalController::class, 'cookies'])->middleware('throttle:20,1')->name('legal.cookies');
Route::middleware('auth:web')->group(function () {
    Route::post('/cuenta/privacidad/aceptar-terminos', [LegalController::class, 'accept'])->middleware('throttle:10,1')->name('legal.accept');
    Route::middleware('can:'.Permission::ManageProviderProfile->value)->group(function () {
        Route::get('/cuenta/prestador/certificacion', [ProfessionalCredentialController::class, 'edit'])->name('trust.edit');
        Route::post('/cuenta/prestador/certificacion', [ProfessionalCredentialController::class, 'update'])->middleware('throttle:10,1')->name('trust.update');
    });
    Route::get('/cuenta/certificacion/{owner}/archivo', [ProfessionalCredentialController::class, 'download'])->whereNumber('owner')->middleware('throttle:20,1')->name('trust.download');
    Route::middleware('can:'.Permission::ReviewProviderProfiles->value)->group(function () {
        Route::get('/administracion/certificaciones', [ProfessionalCredentialController::class, 'index'])->name('trust.review');
        Route::post('/administracion/certificaciones/{owner}', [ProfessionalCredentialController::class, 'decide'])->whereNumber('owner')->middleware('throttle:20,1')->name('trust.decide');
    });
});
