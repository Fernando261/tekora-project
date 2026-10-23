<?php

use Illuminate\Support\Facades\Route;
use App\Models\Emotion;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComunidadController;
use App\Http\Controllers\ProfesionistaController;
use App\Http\Controllers\ProfesionistaAuthController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ProfesionistaCitaController;
use App\Http\Controllers\UserCitaController;
use App\Http\Controllers\ProfesionistaGoogleController;
use App\Http\Controllers\CitasController;
use App\Http\Controllers\ProfesionistaDisponibilidadController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CommentLikeController;

/*
|--------------------------------------------------------------------------
| PÚBLICAS (UNIFICADAS)
|--------------------------------------------------------------------------
| Solo 1 login y 1 registro (usuario/profesionista en la misma vista).
| Usas ?role=prof para abrir la versión profesionista.
*/

// ✅ LOGIN unificado (aquí vive la vista con switch Usuario/Profesionista)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.post');

// ✅ GET login profesionista -> redirige al login unificado (modo profesionista)
Route::get('/login-profesionista', [ProfesionistaAuthController::class, 'showLogin'])->name('profesionista.login');
Route::post('/login-profesionista', [ProfesionistaAuthController::class, 'login'])->middleware('throttle:login')->name('profesionista.login.post');


// ✅ REGISTRO unificado (aquí vive la vista con switch Usuario/Profesionista)
Route::get('/registro', [AuthController::class, 'create'])->name('registro.create');

// ✅ POST registro usuario
Route::post('/registro', [AuthController::class, 'store'])->name('registro.store');

// ✅ GET registro profesionista -> redirige al registro unificado (modo profesionista)
Route::get('/registro-profesionista', function () {
    return redirect()->route('registro.create', ['role' => 'prof']);
})->name('profesionista.create');

// ✅ POST registro profesionista (se mantiene para que el form profesionista postee aquí)
Route::post('/registro-profesionista', [ProfesionistaController::class, 'store'])->name('profesionista.store');


// ✅ Logout usuario
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// ✅ Logout profesionista
Route::post('/logout-profesionista', [ProfesionistaAuthController::class, 'logout'])->name('profesionista.logout');

Route::get('/emociones/{slug}', function ($slug) {
    $emotion = Emotion::where('slug', $slug)->firstOrFail();
    return view('emotion.show', compact('emotion'));
    });


/*
|--------------------------------------------------------------------------
| Forgot password (usuario)
|--------------------------------------------------------------------------
*/
Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])
    ->name('password.request');

Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])
    ->name('password.email');

Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])
    ->name('password.reset');

Route::post('/reset-password', [ResetPasswordController::class, 'reset'])
    ->name('password.update');


/*
|--------------------------------------------------------------------------
| PROFESIONISTA (PROTEGIDAS)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:profesionista'])->group(function () {

    Route::get('/dashboard-profesionista', function () {
        return view('dashboard-profesionista');
    })->name('profesionista.dashboard');

    // Disponibilidad
    Route::get('/profesionista/disponibilidad', [ProfesionistaDisponibilidadController::class, 'index'])
        ->name('prof.dispo');

    Route::post('/profesionista/disponibilidad', [ProfesionistaDisponibilidadController::class, 'store'])
        ->name('prof.dispo.store');

    // Solicitudes de citas
    Route::get('/solicitudes-citas', [ProfesionistaCitaController::class, 'index'])
        ->name('profesionista.solicitudes');

    Route::post('/citas/{id}/aceptar', [ProfesionistaCitaController::class, 'aceptar'])
        ->name('citas.aceptar');

    Route::post('/citas/{id}/rechazar', [ProfesionistaCitaController::class, 'rechazar'])
        ->name('citas.rechazar');

    // Google Calendar connect
    Route::get('/google/connect', [ProfesionistaGoogleController::class, 'redirect'])
        ->name('google.connect');

    Route::get('/google/callback', [ProfesionistaGoogleController::class, 'callback'])
        ->name('google.callback');

    Route::post('/google/disconnect', [ProfesionistaGoogleController::class, 'disconnect'])
        ->name('google.disconnect');
});


/*
|--------------------------------------------------------------------------
| USUARIO (PROTEGIDAS)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:web'])->group(function () {

    Route::get('/', [HomeController::class, 'index'])->name('home');
    
    Route::get('/pdf/emociones/tarjetas', [PdfController::class, 'emotionCards'])
        ->name('pdf.emotions.cards');

    Route::get('/conferencias', function () {
        return view('conferencias');
    });

    // Compatibilidad con enlaces antiguos: el listado real está en /citas.
    Route::get('/consultas', fn () => redirect()->route('user.citas'));

    // Comunidad
    Route::get('/comunidad', [ComunidadController::class, 'index'])->name('comunidad');
    Route::post('/comunidad', [ComunidadController::class, 'store'])->name('comunidad.store');

    Route::post('/posts/{post}/like', [ComunidadController::class, 'like'])->name('like');
    Route::delete('/posts/{post}', [ComunidadController::class, 'destroy'])->name('eliminar');

    // Comentarios
    Route::post('/comments', [CommentController::class, 'store'])->name('comentario_cre');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('eliminar_com');
    Route::post('/comments/{comment}/like', [CommentLikeController::class, 'store']);

    // Perfil
    Route::get('/perfil', [PerfilController::class, 'index'])->name('perfil');
    Route::post('/perfil/foto', [ProfileController::class, 'updatePhoto'])->name('perfil.updatePhoto');
    Route::get('/perfil/editar-nombre', [PerfilController::class, 'editNombre'])->name('perfil.editNombre');
    Route::put('/perfil/actualizar-nombre', [PerfilController::class, 'updateNombre'])->name('perfil.updateNombre');

    // Profesionistas
    Route::get('/profesionistas', [ProfesionistaController::class, 'index'])
        ->name('profesionistas.index');

    // Citas
    Route::get('/citas/create/{profesionista}', [CitasController::class, 'create'])->name('citas.create');

    Route::get('/profesionistas/{profesionista}/availability', [CitasController::class, 'availability'])
        ->name('profesionistas.availability');


    Route::post('/citas', [CitasController::class, 'store'])->name('citas.store');

    // Mis citas (usuario)
    Route::get('/citas', [UserCitaController::class, 'index'])->name('user.citas');
});
