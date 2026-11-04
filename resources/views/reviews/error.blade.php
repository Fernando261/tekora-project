{{-- No consultar navegación ni contadores mientras SQLite está bloqueado. --}}
<x-access-layout title="Revisar reseña">
  <section class="account-card"><h1>No se pudo completar la operación</h1><p class="account-alert is-error" role="alert">{{ $message }}</p><p>Los envíos repetidos no reemplazan una reseña existente.</p><a class="account-button" href="{{ route('catalog.index') }}">Explorar servicios</a></section>
</x-access-layout>
