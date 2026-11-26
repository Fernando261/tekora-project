@if(request()->hasSession() && !in_array(request()->cookie('tekora_preferences_v1'), ['accepted', 'rejected'], true) && !request()->routeIs('legal.show'))
  <section class="account-card trust-cookie-banner" aria-labelledby="cookie-banner-title">
    <h2 id="cookie-banner-title">Preferencias de cookies</h2>
    <p>Usamos cookies esenciales para la sesión y la seguridad. Puedes elegir tus preferencias para cookies opcionales.</p>
    <form class="trust-links" action="{{ route('legal.cookies') }}" method="POST">
      @csrf
      <button class="account-button" type="submit" name="optional" value="rejected">Solo esenciales</button>
      <button class="account-button secondary" type="submit" name="optional" value="accepted">Aceptar opcionales</button>
      <a href="{{ route('legal.show', 'cookies') }}">Ver política y preferencias</a>
    </form>
  </section>
@endif
