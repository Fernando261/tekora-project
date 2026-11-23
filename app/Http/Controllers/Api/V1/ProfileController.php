<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IdentityRequest;
use App\Models\User;
use App\Services\ProfilePhotos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProfileController extends Controller
{
    public function __construct(private readonly ProfilePhotos $photos) {}

    public function show(Request $request): JsonResponse
    {
        return $this->profile($request->user('sanctum'));
    }

    public function update(IdentityRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $user->name = $request->validated('name');
        abort_unless($user->save(), 503);

        return $this->profile($user->refresh());
    }

    public function storePhoto(IdentityRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        abort_unless($this->photos->replace($user, $request->file('photo')), 503);

        return $this->profile($user);
    }

    public function photo(Request $request): Response
    {
        try {
            $path = $this->photos->readablePath($request->user('sanctum'));
            $bytes = $path === null ? null : Storage::disk('public')->get($path);
        } catch (Throwable) {
            abort(503);
        }
        abort_if(! is_string($bytes) || strlen($bytes) > 2048 * 1024, 404);
        $info = @getimagesizefromstring($bytes);
        abort_unless(in_array($info['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($bytes)->header('Content-Type', $info['mime']);
    }

    private function profile(User $user): JsonResponse
    {
        try {
            $photo = $this->photos->readablePath($user);
        } catch (Throwable) {
            abort(503);
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'age' => $user->age, 'gender' => $user->gender, 'email_verified' => $user->email_verified_at !== null,
            'photo_url' => $photo === null ? null : route('api.v1.profile.photo.show', absolute: false)]]);
    }
}
