<?php

namespace App\Http\Controllers;

use App\Models\ProviderProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        return view('account.index', ['user' => $request->user('web')]);
    }

    public function client()
    {
        return view('account.client');
    }

    public function activate()
    {
        return view('account.activate-provider');
    }

    public function provider(Request $request)
    {
        $user = $request->user('web');
        $profile = $user->providerProfile()->first();
        Gate::forUser($user)->authorize($profile ? 'update' : 'create', $profile ?? ProviderProfile::class);

        return view('account.provider', compact('user', 'profile'));
    }
}
