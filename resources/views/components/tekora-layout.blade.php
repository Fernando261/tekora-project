@props(['title' => 'Tekora · Ideas que encuentran talento', 'page' => 'home', 'identity' => false])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="description" content="Encuentra servicios, conversa con prestadores y acuerda una reserva. Una cuenta para contratar y ofrecer en Tekora.">
  <title>{{ $title }}</title>
  @if($identity)
    @vite(['resources/css/tekora/ambient.css', 'resources/css/tekora/tekora.css'])
  @else
  @vite(['resources/css/tekora/ambient.css', 'resources/css/tekora/tekora.css', 'resources/js/tekora/tekora.js'])
  @endif
  @if($identity)<x-identity-assets />@endif
</head>
<body class="tk-page tk-page-{{ $page }} tekora-ambient-page {{ $identity ? 'tk-identity tk-identity-access' : '' }}">
  <x-tekora-ambient />
  <a class="tk-skip" href="#contenido">Saltar al contenido</a>
  <header class="tk-header tk-wrap">
    <x-tekora-brand />
    <details class="tk-navigation" data-navigation open>
      <summary aria-controls="tekora-menu" aria-expanded="true">Menú <span class="tk-menu-lines" aria-hidden="true"></span></summary>
      <nav id="tekora-menu" aria-label="Navegación principal">
        <a href="{{ route('catalog.index') }}">Explorar servicios</a>
        <a href="{{ route('tekora.home') }}#como-funciona">Cómo funciona</a>
        <a href="{{ route('registro.create', ['intent' => 'provider']) }}">Ofrecer servicios</a>
      </nav>
    </details>
    @auth('web')
      <a class="tk-button tk-button-small" href="{{ route('account.index') }}">Mi cuenta <x-tekora-arrow /></a>
    @else
      <a class="tk-button tk-button-small" href="{{ route('login') }}">Entrar <x-tekora-arrow /></a>
    @endauth
  </header>
  @if($page === 'access')<p class="tk-auth-progress" data-auth-progress role="status" aria-live="polite" tabindex="-1"></p>@endif
  <main id="contenido">{{ $slot }}</main>
  <footer class="tk-footer tk-wrap">
    <x-tekora-brand />
    <p>Una cuenta. Tu talento. Nuevas posibilidades.</p>
    <nav aria-label="Enlaces complementarios"><a href="{{ route('public-providers.index') }}">Prestadores</a><a href="{{ route('catalog.index') }}">Servicios</a></nav>
  </footer>
</body>
</html>
