<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderProfileRequest;
use App\Models\ProviderProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProviderProfileController extends Controller
{
    public function store(ProviderProfileRequest $request)
    {
        $user = $request->user('web');
        Gate::forUser($user)->authorize('create', ProviderProfile::class);

        $profile = DB::transaction(function () use ($user, $request) {
            $user = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->providerProfile()->exists()) {
                return null;
            }

            return $user->providerProfile()->create($request->validated());
        });

        if ($profile === null) {
            if ($request->expectsJson()) {
                abort(409, 'La cuenta ya tiene un perfil profesional.');
            }

            return redirect()->route('account.provider', [], 303)
                ->with('error', 'Tu perfil ya existe. Puedes modificarlo desde esta página.');
        }

        return $request->expectsJson() ? response()->json(['provider_profile' => $profile], 201)
            : redirect()->route('account.provider', [], 303)->with('success', 'Perfil profesional creado.');
    }

    public function show(Request $request, ProviderProfile $providerProfile)
    {
        Gate::forUser($request->user('web'))->authorize('view', $providerProfile);

        return $request->expectsJson() ? response()->json(['provider_profile' => $providerProfile])
            : view('account.provider-show', ['profile' => $providerProfile]);
    }

    public function update(ProviderProfileRequest $request, ProviderProfile $providerProfile)
    {
        Gate::forUser($request->user('web'))->authorize('update', $providerProfile);
        $providerProfile->update($request->validated());

        return $request->expectsJson() ? response()->json(['provider_profile' => $providerProfile])
            : redirect()->route('account.provider', [], 303)->with('success', 'Perfil profesional actualizado.');
    }
}
