@props(['title'])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} - MindBloom</title>
  <link rel="stylesheet" href="{{ asset('css/home.css') }}">
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
  <script src="{{ asset('js/account.js') }}" defer></script>
</head>
<body>
  <div class="home-layout">
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="{{ route('account.index') }}">
        <img class="brand-logo" src="{{ asset('img/logo.png') }}" alt="">
        <span class="brand-text"><strong class="brand-title">MindBloom</strong><span class="brand-sub">Cuenta única</span></span>
      </a>
      <x-account-navigation />
    </aside>
    <main class="main-content account-content" id="main-content">
      <div class="content-head"><button class="toggle" id="toggleSidebar" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú">☰</button></div>
      <x-flash-messages />
      {{ $slot }}
    </main>
  </div>
</body>
</html>
