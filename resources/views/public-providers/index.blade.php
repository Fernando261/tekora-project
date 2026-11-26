<x-catalog-layout title="Explorar prestadores" description="Conoce a los prestadores que tienen servicios publicados en Tekora. Explora sus perfiles y encuentra una propuesta para tu proyecto.">
  <header class="mp-hero mp-hero-people"><div class="mp-hero-copy"><span class="mp-kicker" data-market-enter>EL VALOR ESTÁ EN LAS PERSONAS</span><h1 data-market-enter>Conecta con<br>otra <em>perspectiva.</em></h1><p data-market-enter>Conoce a quienes tienen servicios publicados en el catálogo. Descubre qué ofrecen y encuentra una forma de comenzar.</p><span class="mp-directory-count">{{ $providers->total() }} {{ $providers->total() === 1 ? 'prestador con servicios disponibles' : 'prestadores con servicios disponibles' }}</span></div><x-marketplace-scene variant="people" /></header>
  <div class="mp-results-heading"><div><span class="mp-kicker">EXPLORAR PRESTADORES</span><h2>Personas detrás de las ideas.</h2></div><a class="mp-text-link" href="{{ route('catalog.index') }}">Ver todos los servicios <x-tekora-icon name="arrow" /></a></div>
  <form class="account-card catalog-filters" method="GET" action="{{ route('public-providers.index') }}" role="search" aria-label="Buscar prestadores">
    <div><label for="q">Prestador</label><input id="q" name="q" type="search" maxlength="120" value="{{ $filters['q'] ?? '' }}"></div>
    <div><label for="category">Categoría</label><select id="category" name="category"><option value="">Todas</option>
      @foreach($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach
    </select></div>
    <x-trust-filters :filters="$filters" />
    <button class="account-button" type="submit">Buscar</button>
  </form>
  <div class="catalog-grid mp-provider-grid">@forelse($providers as $profile)<x-provider-card :profile="$profile" />@empty<x-marketplace-empty title="Aún no hay prestadores con servicios publicados"><p>Vuelve pronto para conocer sus propuestas.</p><a class="mp-text-link" href="{{ route('catalog.index') }}">Explorar servicios <x-tekora-icon name="arrow" /></a></x-marketplace-empty>@endforelse</div>
  <x-catalog-pagination :paginator="$providers" />
</x-catalog-layout>
