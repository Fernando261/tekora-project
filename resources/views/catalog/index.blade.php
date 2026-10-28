<x-catalog-layout title="Explorar servicios">
  <header class="account-heading catalog-heading"><span class="account-eyebrow">Encuentra a quien sabe hacerlo</span><h1>Servicios técnicos y freelance</h1><p>Explora lo que ofrecen los prestadores y conoce su experiencia.</p></header>
  <form class="account-card catalog-filters" method="GET" action="{{ route('catalog.index') }}" role="search">
    <div><label for="q">¿Qué servicio buscas?</label><input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Por ejemplo, reparación o desarrollo web"></div>
    <div><label for="category">Categoría</label><select id="category" name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach</select></div>
    <div><label for="modality">Modalidad</label><select id="modality" name="modality"><option value="">Todas las modalidades</option>@foreach(\App\Enums\ServiceModality::cases() as $modality)<option value="{{ $modality->value }}" @selected(($filters['modality'] ?? '') === $modality->value)>{{ $modality->label() }}</option>@endforeach</select></div>
    <button class="account-button" type="submit">Buscar</button><a href="{{ route('catalog.index') }}">Limpiar filtros</a>
  </form>
  <p class="catalog-result-count" role="status">{{ $services->total() }} {{ $services->total() === 1 ? 'servicio disponible' : 'servicios disponibles' }}</p>
  <div class="catalog-grid">@forelse($services as $service)<x-service-card :service="$service" />@empty<section class="account-card catalog-empty"><h2>No encontramos servicios</h2><p>Prueba otras palabras o cambia los filtros. También puedes explorar a los prestadores.</p><a href="{{ route('public-providers.index') }}">Explorar prestadores</a></section>@endforelse</div>
  <x-catalog-pagination :paginator="$services" />
</x-catalog-layout>
