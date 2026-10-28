<x-catalog-layout title="Revisar filtros">
  <section class="account-card"><h1>Revisa los filtros de búsqueda</h1><ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul><p><a href="{{ route('catalog.index') }}">Volver al catálogo sin filtros</a></p></section>
</x-catalog-layout>
