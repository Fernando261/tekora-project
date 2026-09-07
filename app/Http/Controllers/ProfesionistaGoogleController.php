<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class ProfesionistaGoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/calendar.events',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent', // fuerza refresh_token
            ])
            ->redirect();
    }

    public function callback()
    {
        // Si alguna vez te da error de state/session, cambia a:
        // $googleUser = Socialite::driver('google')->stateless()->user();
        $googleUser = Socialite::driver('google')->user();

        $prof = auth('profesionista')->user();

        // ✅ Guardar access token
        $prof->google_access_token = $googleUser->token;

        // ✅ NO sobrescribir refresh_token si Google lo manda null
        if (!empty($googleUser->refreshToken)) {
            $prof->google_refresh_token = $googleUser->refreshToken;
        }

        // expiresIn viene en segundos
        $prof->google_token_expires_at = now()->addSeconds($googleUser->expiresIn ?? 3600);

        $prof->google_calendar_id = 'primary';
        $prof->save();

        return redirect()->route('profesionista.dashboard')
            ->with('success', 'Google Calendar conectado correctamente.');
    }

    public function disconnect(Request $request)
    {
        $prof = auth('profesionista')->user();

        $prof->google_access_token = null;
        $prof->google_refresh_token = null;
        $prof->google_token_expires_at = null;
        $prof->google_calendar_id = null;
        $prof->save();

        return redirect()->route('profesionista.dashboard')
            ->with('success', 'Google Calendar desconectado.');
    }
}