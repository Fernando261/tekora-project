<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
   public function updatePhoto(Request $request)
{
    $request->validate([
        'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ]);

    $file = $request->file('photo');

    if (!$file || !$file->isValid()) {
        return back()->withErrors([
            'photo' => 'La imagen no se pudo subir. (Código: ' . ($file?->getError() ?? 'null') . ')'
        ]);
    }

    $user = Auth::user();

    if ($user->profile_photo_path) {
        Storage::disk('public')->delete($user->profile_photo_path);
    }

    $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
    $path = 'avatars/' . $filename;

    $stream = fopen($file->getPathname(), 'r');
    Storage::disk('public')->put($path, $stream);
    if (is_resource($stream)) fclose($stream);

    $user->profile_photo_path = $path;
    $user->save();

    return back()->with('success', 'Foto de perfil actualizada.');
}
}