<x-catalog-layout :title="$profile->display_name" :description="\Illuminate\Support\Str::limit($profile->headline ?: ($profile->bio ?: 'Conoce los servicios publicados por este prestador en Tekora.'), 155)">
  <a class="catalog-back" href="{{ route('public-providers.index') }}"><span aria-hidden="true">←</span> Explorar prestadores</a>
  <header class="mp-profile-hero">
    <div class="mp-profile-copy"><span class="mp-kicker" data-market-enter>PRESENTACIÓN DEL PRESTADOR</span><h1 data-market-enter>{{ $profile->display_name }}</h1>@if($profile->headline)<p class="mp-profile-headline" data-market-enter>{{ $profile->headline }}</p>@endif<p class="account-note">Perfil sin verificación profesional.</p><a class="mp-text-link" href="#published-services">Explorar sus servicios <span aria-hidden="true">↓</span></a></div>
    <x-marketplace-scene variant="identity" :initial="mb_strtoupper(mb_substr(trim($profile->display_name), 0, 1))" />
  </header>
  @if($profile->bio)<section class="account-card mp-profile-bio"><span class="mp-kicker">EN SUS PALABRAS</span><h2>Sobre el prestador</h2><p class="catalog-bio">{{ $profile->bio }}</p></section>@endif
  <div class="mp-results-heading" id="published-services"><div><span class="mp-kicker">SU PROPUESTA, EN ACCIÓN</span><h2 class="catalog-section-title">Servicios publicados</h2></div><p class="catalog-result-count">{{ $services->total() }} {{ $services->total() === 1 ? 'servicio disponible' : 'servicios disponibles' }}</p></div>
  <div class="catalog-grid">@foreach($services as $service)<x-service-card :service="$service" />@endforeach</div>
  <x-catalog-pagination :paginator="$services" label="Páginas de servicios" />
  <div class="mp-public-context"><x-provider-availability :profile="$profile" /><x-public-reviews :reviews="$reviews" :for-provider="true" /></div>
</x-catalog-layout>
