@props(['title', 'dashboard' => false])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} · Tekora</title>
  <link rel="stylesheet" href="{{ asset('css/home.css') }}">
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
  @vite(['resources/css/tekora/ambient.css', 'resources/css/tekora/account-shell.css', 'resources/js/tekora/account-shell.js'])
  @if($dashboard) @vite(['resources/css/tekora/dashboards.css', 'resources/js/tekora/dashboards.js']) @endif
  @stack('styles')
</head>
<body class="tekora-ambient-page tekora-interior tk-account-shell {{ $dashboard ? 'tk-dashboard-page' : 'tk-account-page' }}">
  <x-tekora-ambient />
  <a class="tk-shell-skip" href="#main-content">Saltar al contenido</a>
  <div class="tk-account-frame">
    <details class="tk-shell-navigation" data-account-navigation open>
      <summary aria-controls="sidebar"><x-tekora-icon name="menu" /><span>Menú de tu cuenta</span></summary>
      <aside class="sidebar" id="sidebar">
        <div class="tk-shell-brand"><x-tekora-brand /><p>UN ESPACIO. TODAS TUS POSIBILIDADES.</p></div>
        <p class="tk-nav-caption">NAVEGACIÓN · CUENTA COMPLETA</p>
        <x-account-navigation :tekora="true" />
        <p class="tk-shell-footnote">Los contadores del menú incluyen<br>toda la actividad de tu cuenta.</p>
      </aside>
    </details>
    <div class="tk-shell-workspace">
      <header class="tk-shell-topbar">
        <a href="{{ route('catalog.index') }}" class="tk-shell-explore"><x-tekora-icon name="search" /> Explorar talento <x-tekora-icon name="arrow" /></a>
        <nav aria-label="Tu cuenta"><a class="tk-shell-bell" href="{{ route('notifications.index') }}" aria-label="Notificaciones de toda la cuenta"><x-tekora-icon name="bell" /></a><a class="tk-shell-person" href="{{ route('account.index') }}"><span class="tk-shell-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth('web')->user()->name, 0, 1)) }}</span><span>{{ auth('web')->user()->name }}<small>Mi cuenta Tekora</small></span></a></nav>
      </header>
    <main class="main-content account-content" id="main-content">
      <x-flash-messages />
      {{ $slot }}
    </main>
    <footer class="tk-shell-footer"><span>tekora<span aria-hidden="true">.</span></span><p>Conecta con lo que viene.</p><a href="{{ route('home') }}">Acceso a MindBloom anterior <x-tekora-icon name="arrow" /></a></footer>
    </div>
  </div>
</body>
</html>
