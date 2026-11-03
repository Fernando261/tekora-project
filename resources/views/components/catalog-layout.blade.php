@props(['title'])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} - MindBloom</title>
  <link rel="stylesheet" href="{{ asset('css/account.css') }}">
  <link rel="stylesheet" href="{{ asset('css/catalog.css') }}">
  @stack('styles')
</head>
<body class="account-access catalog-page">
  <a class="catalog-skip" href="#catalog-content">Ir al contenido</a>
  <header class="catalog-header">
    <a class="catalog-brand" href="{{ route('catalog.index') }}"><img src="{{ asset('img/logo.png') }}" alt=""><span>MindBloom<small>Catálogo Tekora</small></span></a>
    <nav aria-label="Catálogo y cuenta">
      <a href="{{ route('catalog.index') }}" @if(request()->routeIs('catalog.*')) aria-current="page" @endif>Explorar servicios</a>
      <a href="{{ route('public-providers.index') }}" @if(request()->routeIs('public-providers.*')) aria-current="page" @endif>Explorar prestadores</a>
      @auth('web')
        <a href="{{ route('account.index') }}">Mi cuenta</a>
      @else
        <a href="{{ route('login') }}">Iniciar sesión</a>
        <a class="account-button secondary" href="{{ route('registro.create', ['intent' => 'provider']) }}">Ofrecer servicios</a>
      @endauth
    </nav>
  </header>
  <main class="catalog-container" id="catalog-content">
    <x-flash-messages />
    {{ $slot }}
  </main>
  <footer class="catalog-footer">Los perfiles y servicios son presentaciones de sus prestadores. Su publicación no acredita verificación profesional.</footer>
</body>
</html>
