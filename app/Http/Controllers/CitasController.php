<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Profesionista;
use App\Models\Cita;
use App\Models\ProfesionistaDisponibilidad;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Carbon\Carbon;

class CitasController extends Controller
{
    /**
     * Vista para agendar con un profesionista
     */
    public function create(Profesionista $profesionista)
    {
        return view('citas.create', compact('profesionista'));
    }

    /**
     * Cliente Google del profesionista (para leer FreeBusy)
     */
    private function googleClientFor(Profesionista $profesionista): GoogleClient
    {
        $client = new GoogleClient();

        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setScopes([GoogleCalendar::CALENDAR_READONLY]);

        if ($profesionista->google_access_token) {
            $client->setAccessToken([
                'access_token'  => $profesionista->google_access_token,
                'refresh_token' => $profesionista->google_refresh_token,
                'expires_in'    => 3600,
                'created'       => time() - 10,
            ]);
        }

        // Refresh automático si expira
        if ($client->getAccessToken() && $client->isAccessTokenExpired()) {
            if (!empty($profesionista->google_refresh_token)) {
                $client->fetchAccessTokenWithRefreshToken($profesionista->google_refresh_token);
                $newToken = $client->getAccessToken();

                if (!empty($newToken['access_token'])) {
                    $profesionista->google_access_token = $newToken['access_token'];
                    $profesionista->google_token_expires_at = now()->addSeconds($newToken['expires_in'] ?? 3600);
                    $profesionista->save();
                }
            }
        }

        return $client;
    }

    /**
     * Devuelve slots disponibles reales para una fecha:
     * - según tabla profesionistas_disponibilidades
     * - menos ocupados de Google Calendar (freebusy) (si está conectado)
     */
    public function availability(Request $request, Profesionista $profesionista)
    {
        $tz = 'America/Mexico_City';
        $dateStr = $request->query('date');

        if (!$dateStr) {
            return response()->json(['slots' => []]);
        }

        // fecha seleccionada
        $date = Carbon::parse($dateStr, $tz)->startOfDay();
        $dow = $date->dayOfWeek; // 0=Dom ... 6=Sáb (igual que tu tabla)

        // 1) traer rangos guardados para ese día
        $ranges = ProfesionistaDisponibilidad::where('profesionista_id', $profesionista->id)
            ->where('day_of_week', $dow)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        if ($ranges->isEmpty()) {
            return response()->json(['slots' => []]);
        }

        $slotMinutes = 50;
        $slots = [];

        // 2) busy de Google (opcional y SEGURO)
        $busyRanges = collect();

        if ($profesionista->google_access_token) {
            try {
                $client = $this->googleClientFor($profesionista);
                $service = new GoogleCalendar($client);
                $calendarId = $profesionista->google_calendar_id ?? 'primary';

                $dayStart = $date->copy()->startOfDay();
                $dayEnd   = $date->copy()->endOfDay();

                $fbReq = new \Google\Service\Calendar\FreeBusyRequest([
                    'timeMin' => $dayStart->toRfc3339String(),
                    'timeMax' => $dayEnd->toRfc3339String(),
                    'timeZone' => $tz,
                    'items' => [['id' => $calendarId]]
                ]);

                $fb = $service->freebusy->query($fbReq);

                $calendars = $fb->getCalendars();

                // ✅ evita "Undefined array key" y nulls
                if (isset($calendars[$calendarId])) {
                    $busy = $calendars[$calendarId]->getBusy() ?? [];

                    $busyRanges = collect($busy)->map(fn($b) => [
                        'start' => Carbon::parse($b->start, $tz),
                        'end'   => Carbon::parse($b->end, $tz),
                    ]);
                }
            } catch (\Throwable $e) {
                // ✅ IMPORTANTÍSIMO: no tronar availability
                \Log::warning('FreeBusy fallo (se ignora)', [
                    'profesionista_id' => $profesionista->id,
                    'calendar_id' => $profesionista->google_calendar_id ?? 'primary',
                    'exception' => $e::class,
                    'code' => (int) $e->getCode(),
                ]);

                $busyRanges = collect();
            }
        }

        // 3) generar slots solo dentro de rangos guardados
        foreach ($ranges as $r) {
            $rangeStart = $date->copy()->setTimeFromTimeString(substr($r->start_time, 0, 5));
            $rangeEnd   = $date->copy()->setTimeFromTimeString(substr($r->end_time, 0, 5));

            $cursor = $rangeStart->copy();

            while ($cursor->copy()->addMinutes($slotMinutes)->lte($rangeEnd)) {
                $slotStart = $cursor->copy();
                $slotEnd = $cursor->copy()->addMinutes($slotMinutes);

                $overlap = $busyRanges->first(fn($b) =>
                    $slotStart->lt($b['end']) && $slotEnd->gt($b['start'])
                );

                if (!$overlap) {
                    $slots[] = [
                        'start' => $slotStart->toIso8601String(),
                        'end'   => $slotEnd->toIso8601String(),
                        'label' => $slotStart->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    ];
                }

                $cursor->addMinutes($slotMinutes);
            }
        }

        return response()->json(['slots' => $slots]);
    }

    /**
     * Guardar solicitud (PENDIENTE) - NO crea evento en Google aquí
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'profesionista_id' => 'required|exists:profesionistas,id',
            'starts_at'        => 'required|date',
            'ends_at'          => 'required|date|after:starts_at',
        ]);

        $tz = 'America/Mexico_City';

        $profesionista = Profesionista::findOrFail($data['profesionista_id']);

        $start = Carbon::parse($data['starts_at'], $tz);
        $end   = Carbon::parse($data['ends_at'], $tz);

        // Anti doble-reserva contra BD (aceptadas)
        $collision = Cita::where('profesionista_id', $profesionista->id)
            ->where(function ($q) {
                $q->where('estado', 'aceptada')
                  ->orWhere('status', 'scheduled');
            })
            ->where(function ($q) use ($start, $end) {
                $q->where('start_datetime', '<', $end->toDateTimeString())
                  ->where('end_datetime', '>', $start->toDateTimeString());
            })
            ->exists();

        if ($collision) {
            return back()
                ->withInput()
                ->with('error', '❌ Ese horario ya fue tomado. Elige otro.');
        }

        try {
            Cita::create([
                'user_id'          => auth()->id(),
                'profesionista_id' => $profesionista->id,

                'starts_at'        => $start->toDateTimeString(),
                'ends_at'          => $end->toDateTimeString(),

                'start_datetime'   => $start->toDateTimeString(),
                'end_datetime'     => $end->toDateTimeString(),

                'google_event_id'  => null,
                'status'           => 'pending',
                'estado'           => 'pendiente',
            ]);

            return redirect()
                ->route('citas.create', ['profesionista' => $profesionista->id])
                ->with('success', '✅ Solicitud enviada. Queda pendiente de aprobación.');
        } catch (\Throwable $e) {
            \Log::error('Error creando solicitud de cita', [
                'profesionista_id' => $profesionista->id,
                'user_id'          => auth()->id(),
                'exception'        => $e::class,
                'code'             => (int) $e->getCode(),
            ]);

            return back()
                ->withInput()
                ->with('error', '❌ Ocurrió un error al enviar la solicitud. Intenta de nuevo.');
        }
    }
}