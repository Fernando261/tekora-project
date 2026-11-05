<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\ClientResource;
use App\Services\MobileTokens;
use App\Support\Api\ApiError;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(LoginRequest $request, MobileTokens $tokens)
    {
        $token = $tokens->login($request->validated());
        if (! $token) {
            return ApiError::response(401, 'invalid_credentials', 'No se pudo iniciar sesión con los datos proporcionados.');
        }

        return response()->json(['data' => ['token_type' => 'Bearer', 'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toISOString(),
            'user' => new ClientResource($token->accessToken->tokenable)]], 201);
    }

    public function me(Request $request): ClientResource
    {
        return new ClientResource($request->user('sanctum'));
    }

    public function logout(Request $request)
    {
        $request->user('sanctum')->currentAccessToken()->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }
}
