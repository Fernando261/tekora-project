<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cita;
use App\Models\Profesionista;
use Carbon\Carbon;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;

class ProfesionistaCitaController extends Controller
{
    public function index()
    {
        $profesionista = auth('profesionista')->user();

        $citas = Cita::where('profesionista_id', $profesionista->id)
            ->where(function ($q) {
                $q->where('estado', 'pendiente')
                  ->orWhereNull('estado');
            })
            ->orderByDesc('start_datetime')
            ->get();

        return view('profesionistas.solicitudes-citas', compact('citas'));
    }

    /**
     * Cliente Google del profesionista (ESCRITURA para insertar evento)
     */
    private function googleClientForWrite(Profesionista $profesionista): GoogleClient
    {
        $client = new GoogleClient();

        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));

        // ✅ Escritura
        $client->setScopes([GoogleCalendar::CALENDAR]);

        // ✅ Token (sin inventar created/expires_in)
        if (!empty($profesionista->google_access_token)) {
            $token = [
                'access_token' => $profesionista->google_access_token,
            ];

            if (!empty($profesionista->google_refresh_token)) {
                $token['refresh_token'] = $profesionista->google_refresh_token;
            }

            // Si guardas expires_at en DB, úsalo para que el SDK sepa si expiró
            if (!empty($profesionista->google_token_expires_at)) {
                $token['expires_at'] = Carbon::parse($profesionista->google_token_expires_at)->timestamp;
            }

            $client->setAccessToken($token);
        }

        // ✅ Refresh real
        if ($client->getAccessToken() && $client->isAccessTokenExpired()) {
            if (empty($profesionista->google_refresh_token)) {
                // No hay manera de refrescar -> pedir reconectar
                throw new \Exception('GOOGLE_RECONNECT_REQUIRED');
            }

            $client->fetchAccessTokenWithRefreshToken($profesionista->google_refresh_token);
            $new = $client->getAccessToken();

            if (empty($new['access_token'])) {
                throw new \Exception('GOOGLE_REFRESH_FAILED');
            }

            $profesionista->google_access_token = $new['access_token'];
            $profesionista->google_token_expires_at = now()->addSeconds($new['expires_in'] ?? 3600);
            $profesionista->save();
        }

        return $client;
    }

    public function aceptar($id)
    {
        $prof = auth('profesionista')->user();

        $cita = Cita::where('id', $id)
            ->where('profesionista_id', $prof->id)
            ->firstOrFail();

        // ✅ Solo permitir aceptar si sigue pendiente
        if (($cita->estado ?? 'pendiente') !== 'pendiente') {
            return back()->with('error', 'Esta solicitud ya fue procesada.');
        }

        // ✅ Si no tiene access token, no está conectado
        if (empty($prof->google_access_token)) {
            return back()->with('error', 'Conecta tu Google Calendar antes de aceptar citas.');
        }

        $tz = 'America/Mexico_City';
        $calendarId = $prof->google_calendar_id ?? 'primary';

        $start = Carbon::parse($cita->start_datetime, $tz);
        $end   = Carbon::parse($cita->end_datetime, $tz);

        try {
            $client = $this->googleClientForWrite($prof);
            $service = new GoogleCalendar($client);

            /**
             * ✅ (Opcional recomendado) Chequeo final FreeBusy para evitar doble reserva
             */
            $fbReq = new \Google\Service\Calendar\FreeBusyRequest([
                'timeMin' => $start->toRfc3339String(),
                'timeMax' => $end->toRfc3339String(),
                'timeZone' => $tz,
                'items' => [['id' => $calendarId]],
            ]);

            $fb = $service->freebusy->query($fbReq);
            $busy = $fb->getCalendars()[$calendarId]->getBusy() ?? [];

            if (!empty($busy)) {
                return back()->with('error', 'Ese horario ya no está disponible en tu Google Calendar.');
            }

            // 1) Crear evento en Google
            $event = new \Google\Service\Calendar\Event([
                'summary' => 'Cita MindBloom',
                'description' => 'Cita aceptada en MindBloom.',
                'start' => [
                    'dateTime' => $start->toRfc3339String(),
                    'timeZone' => $tz,
                ],
                'end' => [
                    'dateTime' => $end->toRfc3339String(),
                    'timeZone' => $tz,
                ],
            ]);

            $created = $service->events->insert($calendarId, $event);

            // 2) Actualizar BD
            $cita->estado = 'aceptada';
            $cita->status = 'scheduled';
            $cita->google_event_id = $created->id;
            $cita->save();

            return back()->with('success', 'Cita aceptada y agendada ✅');

        } catch (\Throwable $e) {

            // Mensajes bonitos para el usuario
            $msg = $e->getMessage();

            if ($msg === 'GOOGLE_RECONNECT_REQUIRED') {
                return back()->with('error', 'Tu Google Calendar necesita reconexión. Ve a “Google Calendar” y vuelve a conectar.');
            }

            if (str_contains($msg, '401') || str_contains($msg, 'UNAUTHENTICATED') || str_contains($msg, 'Invalid Credentials')) {
                return back()->with('error', 'Tu sesión de Google expiró. Vuelve a conectar Google Calendar y reintenta.');
            }

            \Log::error('Error al aceptar y agendar cita', [
                'cita_id' => $cita->id,
                'profesionista_id' => $prof->id,
                'calendar_id' => $calendarId,
                'error' => $msg,
            ]);

            return back()->with('error', 'Ocurrió un error al aceptar y agendar. Intenta de nuevo.');
        }
    }

    public function rechazar($id)
    {
        $prof = auth('profesionista')->user();

        $cita = Cita::where('id', $id)
            ->where('profesionista_id', $prof->id)
            ->firstOrFail();

        if (($cita->estado ?? 'pendiente') !== 'pendiente') {
            return back()->with('error', 'Esta solicitud ya fue procesada.');
        }

        $cita->estado = 'rechazada';
        $cita->status = 'rejected';
        $cita->save();

        return back()->with('success', 'Cita rechazada ✅');
    }
}