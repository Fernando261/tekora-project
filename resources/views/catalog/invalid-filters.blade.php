<x-catalog-layout title="Revisar filtros">
  <section class="account-card mp-filter-error" role="alert" aria-labelledby="market-filter-error">
    <span class="mp-kicker">VOLVAMOS A ENCONTRAR EL CAMINO</span>
    <h1 id="market-filter-error">Revisa los filtros de búsqueda</h1>
    <ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>
    <p><a class="account-button secondary" href="{{ route('catalog.index') }}">Volver al catálogo sin filtros</a></p>
  </section>
</x-catalog-layout>
