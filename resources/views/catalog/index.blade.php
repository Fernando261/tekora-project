<x-catalog-layout title="Servicios técnicos y freelance">
  <header class="mp-hero mp-hero-discover">
    <div class="mp-hero-copy"><span class="mp-kicker" data-market-enter>EL SIGUIENTE PASO EMPIEZA AQUÍ</span><h1 data-market-enter>Servicios para<br>lo que <em>viene.</em></h1><p data-market-enter>Tu idea merece encontrar a quien sabe darle forma. Explora servicios técnicos y freelance, conoce a sus prestadores y comienza una conversación.</p><a class="mp-text-link" href="#market-search">Encuentra tu próximo servicio <span aria-hidden="true">↓</span></a></div>
    <x-marketplace-scene />
  </header>
  <form class="account-card catalog-filters" id="market-search" method="GET" action="{{ route('catalog.index') }}" role="search" aria-label="Buscar servicios">
    <div class="mp-search-field"><label for="q">¿Qué servicio buscas?</label><div class="mp-input-wrap"><x-tekora-icon name="search" /><input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Una idea, una solución, un talento…"></div></div>
    <div><label for="category">Categoría</label><select id="category" name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach</select></div>
    <div><label for="modality">Modalidad</label><select id="modality" name="modality"><option value="">Todas las modalidades</option>@foreach(\App\Enums\ServiceModality::cases() as $modality)<option value="{{ $modality->value }}" @selected(($filters['modality'] ?? '') === $modality->value)>{{ $modality->label() }}</option>@endforeach</select></div>
    <button class="account-button" type="submit">Buscar <x-tekora-icon name="arrow" /></button><a class="mp-reset" href="{{ route('catalog.index') }}">Limpiar filtros</a>
  </form>
  <div class="mp-results-heading"><div><span class="mp-kicker">EXPLORA LAS POSIBILIDADES</span><h2>Talento, en acción.</h2></div><p class="catalog-result-count" role="status">{{ $services->total() }} {{ $services->total() === 1 ? 'servicio disponible' : 'servicios disponibles' }}</p></div>
  <div class="catalog-grid">@forelse($services as $service)<x-service-card :service="$service" />@empty<x-marketplace-empty title="No encontramos servicios"><p>Prueba otras palabras o cambia los filtros. También puedes explorar a los prestadores.</p><a class="mp-text-link" href="{{ route('public-providers.index') }}">Explorar prestadores <x-tekora-icon name="arrow" /></a></x-marketplace-empty>@endforelse</div>
  <x-catalog-pagination :paginator="$services" />
</x-catalog-layout>
