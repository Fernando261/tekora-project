<x-account-layout title="Revisar filtros de solicitudes">
  <section class="account-card" role="alert"><h1>Revisa los filtros</h1><ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul><p><a href="{{ route($listRoute) }}">Volver sin filtros</a></p></section>
</x-account-layout>
