<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Profesionista;


    class ProfesionistaController extends Controller
{

    public function index()
{
    $profesionistas = Profesionista::all();
    return view('profesionistas.index', compact('profesionistas'));
}

    public function create()
    {
        return redirect()->route('registro.create', ['intent' => 'provider']);
    }

    public function store(Request $request)
    {
        $message = 'El registro profesional anterior está cerrado. Crea una cuenta única para contratar y ofrecer servicios.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message,
                'registration_url' => route('registro.create', ['intent' => 'provider'])], 410);
        }

        return redirect()->route('registro.create', ['intent' => 'provider'], 303)->with('info', $message);
    }


}
