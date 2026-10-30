<?php

use App\Http\Controllers\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->group(function () {
    Route::get('/cuenta/mensajes', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('/cuenta/solicitudes/{inquiry}/mensajes/leer', [ConversationController::class, 'read'])
        ->whereNumber('inquiry')->name('conversations.read');
});
