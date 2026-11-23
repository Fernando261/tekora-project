<?php

namespace App\Http\Controllers;

use App\Services\AccountPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ResetPasswordController extends Controller
{
    public function showResetForm($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function reset(Request $request, AccountPasswords $passwords)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $status = $passwords->reset($request->only('email', 'password', 'token'));

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Contraseña actualizada correctamente')
            : back()->withErrors(['email' => [__($status)]]);
    }
}
