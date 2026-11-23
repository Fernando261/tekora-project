<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Enums\RoleName;
use App\Enums\Permission;
use App\Http\Requests\RegisterUserRequest;
use App\Services\AccountRegistration;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{
    public function create()
    {
        return view('auth.registro');
    }

    public function store(RegisterUserRequest $request, AccountRegistration $registration)
    {
        $provider = in_array($request->validated('intent'), ['provider', 'both'], true);
        if (! $registration->create($request->validated(), provider: $provider)) {
            throw ValidationException::withMessages(['email' => $request->messages()['email.unique']]);
        }

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
        $user = Auth::guard('web')->user();
        $destination = $user->hasPermission(Permission::ManageProviderProfile) ? route('account.provider')
            : ($user->hasRole(RoleName::Client) ? route('account.index') : '/');

        return redirect()->intended($destination);
    }

    return back()->withErrors([
        'email' => 'Las credenciales proporcionadas no coinciden.',
    ])->withInput($request->only('email'));
}

public function logout(Request $request)
{
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/login');
}

}
