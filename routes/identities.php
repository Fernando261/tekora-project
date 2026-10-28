<?php

use App\Enums\Permission;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\ProviderProfileController;
use App\Http\Controllers\RoleMembershipController;
use App\Http\Controllers\UserCitaController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;

// Nuevas operaciones. Los flujos anteriores mantienen sus guards y permisos.
Route::middleware('auth:web')->group(function () {
    Route::get('/cuenta', [AccountController::class, 'index'])->name('account.index');
    Route::get('/cuenta/cliente', [AccountController::class, 'client'])
        ->middleware('can:'.Permission::ViewClientAppointments->value)->name('account.client');
    Route::get('/cuenta/activar-prestador', [AccountController::class, 'activate'])->name('account.activate-provider');
    Route::get('/cuenta/prestador', [AccountController::class, 'provider'])
        ->middleware('can:'.Permission::ManageProviderProfile->value)->name('account.provider');
    Route::get('/cuenta/identidad', [IdentityController::class, 'show'])->name('identity.show');
    Route::post('/cuenta/roles', [RoleMembershipController::class, 'store'])->name('identity.roles.store');

    Route::get('/cuenta/citas', [UserCitaController::class, 'index'])
        ->middleware('can:'.Permission::ViewClientAppointments->value)->name('identity.citas');

    Route::post('/perfil-profesional', [ProviderProfileController::class, 'store'])->name('provider-profiles.store');
    Route::get('/perfiles-profesionales/{providerProfile}', [ProviderProfileController::class, 'show'])->name('provider-profiles.show');
    Route::put('/perfiles-profesionales/{providerProfile}', [ProviderProfileController::class, 'update'])->name('provider-profiles.update');

    Route::middleware('can:'.Permission::ManageAccountRoles->value)->group(function () {
        Route::get('/administracion/roles', [RoleMembershipController::class, 'index'])->name('identity.roles.index');
        Route::put('/administracion/usuarios/{user}/roles', [RoleMembershipController::class, 'update'])->name('identity.roles.update');
    });
});
