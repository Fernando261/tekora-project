<x-catalog-layout :title="$service->title">
  <a class="catalog-back" href="{{ route('catalog.index') }}">← Explorar servicios</a>
  <article class="account-card catalog-detail">
    <div class="catalog-tags"><span>{{ $service->category->name }}</span><span>{{ $service->modality->label() }}</span></div>
    <h1>{{ $service->title }}</h1><p class="catalog-bio">{{ $service->description }}</p>
    <hr><h2>Sobre el prestador</h2><h3>{{ $service->providerProfile->display_name }}</h3>
    @if($service->providerProfile->headline)<p>{{ $service->providerProfile->headline }}</p>@endif
    <p>Publicación sin verificación profesional.</p>
    <a class="account-button secondary" href="{{ route('public-providers.show', $service->providerProfile) }}">Conocer al prestador y sus servicios</a>
  </article>
</x-catalog-layout>
