@props(['title'])
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} · Tekora</title>
  <link rel="preload" href="{{ asset('fonts/tekora/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
  <x-identity-assets :scripts="false" />
</head><body class="tk-identity tk-identity-fallback">
  <div class="tk-identity-fallback-wrap"><div class="tk-identity-brand"><x-tekora-brand /></div><main id="main-content">{{ $slot }}</main><footer>Tekora · Revisa el estado antes de continuar.</footer></div>
</body></html>
