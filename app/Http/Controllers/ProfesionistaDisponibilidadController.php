<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProfesionistaDisponibilidad;
use Illuminate\Support\Facades\Validator;

class ProfesionistaDisponibilidadController extends Controller
{
    public function index()
    {
        $prof = auth('profesionista')->user();

        $items = ProfesionistaDisponibilidad::where('profesionista_id', $prof->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $byDay = $items->groupBy('day_of_week');

        return view('profesionistas.disponibilidad', compact('prof', 'byDay'));
    }

    public function store(Request $request)
    {
        $prof = auth('profesionista')->user();

        $validator = Validator::make($request->all(), [
            'ranges' => ['required', 'array', 'min:1'],
            'ranges.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'ranges.*.start_time'  => ['required', 'date_format:H:i'],
            'ranges.*.end_time'    => ['required', 'date_format:H:i'],
        ], [
            'ranges.required' => 'Agrega al menos un rango.',
            'ranges.min' => 'Agrega al menos un rango.',
            'ranges.*.day_of_week.required' => 'Selecciona el día de la semana.',
            'ranges.*.start_time.required'  => 'Selecciona la hora de inicio.',
            'ranges.*.end_time.required'    => 'Selecciona la hora de fin.',
            'ranges.*.start_time.date_format' => 'La hora de inicio no es válida.',
            'ranges.*.end_time.date_format'   => 'La hora de fin no es válida.',
        ]);

        $validator->after(function ($v) use ($request) {
            $ranges = $request->input('ranges', []);
            foreach ($ranges as $idx => $r) {
                $start = $r['start_time'] ?? null;
                $end   = $r['end_time'] ?? null;

                if ($start && $end && $end <= $start) {
                    $v->errors()->add("ranges.$idx.end_time", 'La hora de fin debe ser mayor a la de inicio.');
                }
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // borra y vuelve a guardar (simple y efectivo)
        ProfesionistaDisponibilidad::where('profesionista_id', $prof->id)->delete();

        foreach ($data['ranges'] as $r) {
            ProfesionistaDisponibilidad::create([
                'profesionista_id' => $prof->id,
                'day_of_week' => (int) $r['day_of_week'],
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time'],
                'is_active' => true,
            ]);
        }

        return back()->with('success', 'Disponibilidad guardada ✅');
    }
}