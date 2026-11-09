@props(['title', 'description' => 'Descubre servicios técnicos y freelance, conoce a sus prestadores y conversa sobre tu proyecto en Tekora.'])
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="description" content="{{ $description }}">
  <title>{{ $title }} · Tekora</title>
  <link rel="preload" href="{{ asset('fonts/tekora/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="{{ asset('fonts/tekora/space-grotesk-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
  @vite(['resources/css/tekora/ambient.css', 'resources/css/tekora/marketplace.css', 'resources/js/tekora/marketplace.js'])
  @stack('styles')
</head>
<body class="tk-marketplace tk-mp tekora-ambient-page" data-marketplace>
  <x-tekora-ambient />
  <a class="catalog-skip" href="#catalog-content">Ir al contenido</a>
  <header class="mp-header mp-wrap">
    <x-tekora-brand />
    <nav aria-label="Catálogo y cuenta">
      <a href="{{ route('catalog.index') }}" @if(request()->routeIs('catalog.*')) aria-current="page" @endif>Servicios</a>
      <a href="{{ route('public-providers.index') }}" @if(request()->routeIs('public-providers.*')) aria-current="page" @endif>Prestadores</a>
      <a href="{{ route('registro.create', ['intent' => 'provider']) }}">Ofrecer talento</a>
    </nav>
    @auth('web')<a class="mp-header-account" href="{{ route('account.index') }}">Mi cuenta <x-tekora-icon name="arrow" /></a>@else<a class="mp-header-account" href="{{ route('login') }}">Entrar <x-tekora-icon name="arrow" /></a>@endauth
  </header>
  <main class="catalog-container mp-wrap" id="catalog-content">
    <x-flash-messages />
    {{ $slot }}
  </main>
  <footer class="mp-footer mp-wrap">
    <div><x-tekora-brand /><p>Tu talento. Una nueva posibilidad.</p></div>
    <nav aria-label="Enlaces complementarios"><a href="{{ route('catalog.index') }}">Explorar servicios</a><a href="{{ route('public-providers.index') }}">Conocer prestadores</a><a href="{{ route('tekora.home') }}#como-funciona">Cómo funciona Tekora</a></nav>
    <p class="mp-disclaimer">Los perfiles y servicios son presentaciones de sus prestadores. Su publicación no acredita verificación profesional.</p>
    <span class="mp-footer-signature" aria-hidden="true">IDEAS QUE ENCUENTRAN TALENTO.</span>
  </footer>
</body>
</html>
