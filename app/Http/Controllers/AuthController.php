<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Enums\RoleName;
use App\Services\RoleMemberships;


class AuthController extends Controller
{
    public function create()
    {
        return view('auth.registro');
    }

    public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'age' => 'required|integer|min:1',
        'gender' => 'required|string',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:6',
    ]);

    DB::transaction(function () use ($request) {
        $user = User::create([
            'name' => $request->name,
            'age' => $request->age,
            'gender' => $request->gender,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        app(RoleMemberships::class)->enroll($user, RoleName::Client);
    });

    return redirect()->route('login')->with('success', 'Registro exitoso. Inicia sesión.');
}

public function showLogin()
{
    return view('login'); 
}

public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (Auth::guard('web')->attempt($credentials)) {
        $request->session()->regenerate();
        return redirect()->intended('/');
    }

    return back()->withErrors([
        'email' => 'Las credenciales proporcionadas no coinciden.',
    ]);
}

public function logout(Request $request)
{
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/login');
}

}
