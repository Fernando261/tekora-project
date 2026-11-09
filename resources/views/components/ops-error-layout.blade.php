@props(['title'])
{{-- Standalone fallback: no navigation, counters, user access or database reads. --}}
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} · Tekora</title>
  @unless(app()->isLocal())
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'">
  @endunless
  <link rel="preload" href="{{ asset('fonts/tekora/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
  @vite(['resources/css/tekora/operations.css'])
</head>
<body class="tk-ops tk-ops-fallback">
  <div class="tk-ops-fallback-wrap">
    <div class="tk-ops-brand"><x-tekora-brand /></div>
    <main id="main-content"><p class="account-eyebrow">Revisa antes de continuar</p>{{ $slot }}</main>
    <footer>Tekora · Tu información y tus decisiones, bajo tu control.</footer>
  </div>
</body>
</html>
