<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Profesionista;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;


    class ProfesionistaController extends Controller
{

    public function index()
{
    $profesionistas = Profesionista::all();
    return view('profesionistas.index', compact('profesionistas'));
}

    public function create()
    {
        return view('auth.registro_profesionista');
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required',
            'email' => 'required|email|unique:profesionistas,email',
            'password' => 'required|min:6|confirmed',
            'cedula' => 'required'
        ]);


        Profesionista::create([
            'nombre' => $request->nombre,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'cedula' => $request->cedula,
        ]);

        return redirect()->route('profesionista.create')->with('success', 'Registro exitoso');
    }


}
