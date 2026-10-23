<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user('web');

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
            'roles' => $user->roles()->orderBy('slug')->pluck('slug'),
            'provider_profile_id' => $user->providerProfile()->value('id'),
        ]);
    }
}
