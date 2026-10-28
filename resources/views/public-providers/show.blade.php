<x-catalog-layout :title="$profile->display_name">
  <a class="catalog-back" href="{{ route('public-providers.index') }}">← Explorar prestadores</a>
  <section class="account-card catalog-detail"><span class="account-eyebrow">Presentación del prestador</span><h1>{{ $profile->display_name }}</h1>@if($profile->headline)<h2>{{ $profile->headline }}</h2>@endif<p class="catalog-bio">{{ $profile->bio }}</p><p class="account-note">Perfil sin verificación profesional.</p></section>
  <h2 class="catalog-section-title">Servicios publicados</h2>
  <div class="catalog-grid">@foreach($services as $service)<x-service-card :service="$service" />@endforeach</div>
  <x-catalog-pagination :paginator="$services" />
</x-catalog-layout>
