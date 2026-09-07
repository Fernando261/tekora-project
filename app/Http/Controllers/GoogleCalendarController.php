<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;

class GoogleCalendarController extends Controller
{
  private function client(): GoogleClient
  {
    $client = new GoogleClient();
    $client->setClientId(config('services.google.client_id'));
    $client->setClientSecret(config('services.google.client_secret'));
    $client->setRedirectUri(config('services.google.redirect'));

    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setScopes([GoogleCalendar::CALENDAR]);

    return $client;
  }

  public function redirect()
  {
    return redirect()->away($this->client()->createAuthUrl());
  }

  public function callback(Request $request)
  {
    $client = $this->client();
    $token = $client->fetchAccessTokenWithAuthCode($request->get('code'));

    if (isset($token['error'])) {
      return redirect('/perfil')->withErrors(['google' => $token['error_description'] ?? 'Error de Google OAuth']);
    }

    $user = auth()->user(); // este usuario debe ser el profesionista
    $user->google_access_token = json_encode($token);

    // importante: refresh_token solo llega la 1ra vez o si usas prompt=consent
    $user->google_refresh_token = $token['refresh_token'] ?? $user->google_refresh_token;

    $user->google_token_expires_at = now()->addSeconds($token['expires_in'] ?? 3600);
    $user->google_calendar_id = 'primary';
    $user->save();

    return redirect('/perfil')->with('ok', 'Google Calendar conectado ✅');
  }
}