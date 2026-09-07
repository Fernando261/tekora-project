<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cita;

class UserCitaController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        // Ajusta la relación si tu modelo tiene belongsTo a Profesionista
        $citas = Cita::with('profesionista')
            ->where('user_id', $userId)
            ->orderByDesc('start_datetime')
            ->get();

        return view('citas.mis-citas', compact('citas'));
    }
}