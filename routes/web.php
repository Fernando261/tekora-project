<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Middleware\TekoraPageHeaders;
use Illuminate\Support\Facades\Route;

require __DIR__.'/reservations.php';
require __DIR__.'/reviews.php';
require __DIR__.'/identities.php';
require __DIR__.'/catalog.php';
require __DIR__.'/inquiries.php';
require __DIR__.'/conversations.php';
require __DIR__.'/availability.php';
require __DIR__.'/notifications.php';

Route::get('/', \App\Http\Controllers\TekoraHomeController::class)->middleware(TekoraPageHeaders::class)->name('tekora.home');
Route::get('/login', [AuthController::class, 'showLogin'])->middleware(TekoraPageHeaders::class)->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.post');
Route::get('/registro', [AuthController::class, 'create'])->middleware(TekoraPageHeaders::class)->name('registro.create');
Route::post('/registro', [AuthController::class, 'store'])->name('registro.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');

Route::middleware('auth:web')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'index'])->name('perfil');
    Route::post('/perfil/foto', [ProfileController::class, 'updatePhoto'])->name('perfil.updatePhoto');
    Route::get('/perfil/editar-nombre', [PerfilController::class, 'editNombre'])->name('perfil.editNombre');
    Route::put('/perfil/actualizar-nombre', [PerfilController::class, 'updateNombre'])->name('perfil.updateNombre');
});
