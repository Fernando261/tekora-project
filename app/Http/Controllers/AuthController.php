<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Enums\RoleName;
use App\Services\RoleMemberships;
use App\Enums\Permission;
use App\Http\Requests\RegisterUserRequest;


class AuthController extends Controller
{
    public function create()
    {
        return view('auth.registro');
    }

    public function store(RegisterUserRequest $request)
{
    DB::transaction(function () use ($request) {
        $user = User::create([
            'name' => $request->name,
            'age' => $request->age,
            'gender' => $request->gender,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        app(RoleMemberships::class)->enroll($user, RoleName::Client);
        if (in_array($request->validated('intent'), ['provider', 'both'], true)) {
            app(RoleMemberships::class)->enroll($user, RoleName::Provider);
        }
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
