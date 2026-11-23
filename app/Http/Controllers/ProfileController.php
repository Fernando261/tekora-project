<?php

namespace App\Http\Controllers;

use App\Services\ProfilePhotos;
use App\Support\ProfileInput;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function updatePhoto(Request $request)
    {
        $request->session()->forget('success');
        $request->validate(['photo' => ProfileInput::photo()]);

        if (! app(ProfilePhotos::class)->replace($request->user('web'), $request->file('photo'))) {
            $request->session()->forget('success');

            return back()->withErrors(['photo' => 'No se pudo actualizar la foto. Inténtalo de nuevo.']);
        }

        return back()->with('success', 'Foto de perfil actualizada.');
    }
}
