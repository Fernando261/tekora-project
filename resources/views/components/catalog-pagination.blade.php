@props(['paginator'])
@if($paginator->hasPages())
  <nav class="catalog-pagination" aria-label="Páginas de resultados">
    @if($paginator->onFirstPage())<span>Anterior</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>@endif
    <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>@else<span>Siguiente</span>@endif
  </nav>
@endif
