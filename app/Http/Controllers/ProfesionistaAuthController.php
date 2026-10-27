<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfesionistaAuthController extends Controller
{
    public function showLogin()
    {
        return redirect()->route('login', ['role' => 'prof']);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('profesionista')->attempt($credentials)) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard-profesionista');
        }

        return back()->withErrors([
            'email' => 'Las credenciales proporcionadas no son válidas.',
        ])->withInput($request->only('email'))->with('legacy_login', true);
    }

    public function logout(Request $request)
    {
        Auth::guard('profesionista')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login-profesionista');
    }
}
