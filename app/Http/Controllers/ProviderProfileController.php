<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderProfileRequest;
use App\Models\ProviderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProviderProfileController extends Controller
{
    public function store(ProviderProfileRequest $request): JsonResponse
    {
        $user = $request->user('web');
        Gate::forUser($user)->authorize('create', ProviderProfile::class);

        $profile = DB::transaction(function () use ($user, $request) {
            $user = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->providerProfile()->exists(), 409, 'La cuenta ya tiene un perfil profesional.');

            return $user->providerProfile()->create($request->validated());
        });

        return response()->json(['provider_profile' => $profile], 201);
    }

    public function show(Request $request, ProviderProfile $providerProfile): JsonResponse
    {
        Gate::forUser($request->user('web'))->authorize('view', $providerProfile);

        return response()->json(['provider_profile' => $providerProfile]);
    }

    public function update(ProviderProfileRequest $request, ProviderProfile $providerProfile): JsonResponse
    {
        Gate::forUser($request->user('web'))->authorize('update', $providerProfile);
        $providerProfile->update($request->validated());

        return response()->json(['provider_profile' => $providerProfile]);
    }
}
