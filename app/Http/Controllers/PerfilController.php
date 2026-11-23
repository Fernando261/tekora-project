<?php

namespace App\Http\Controllers;

use App\Support\ProfileInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PerfilController extends Controller
{
    public function index()
    {
        return view('perfil');
    }

    public function editNombre()
    {
        return view('editar-nombre');
    }

    public function updateNombre(Request $request)
    {
        $request->validate([
            'name' => ProfileInput::name(),
        ]);

        $user = Auth::user();
        $user->name = $request->name;
        $user->save();

        return redirect()->route('perfil')
            ->with('success', 'Nombre actualizado correctamente');
    }
}
