@php($heading = $heading ?? 'No se pudo guardar la disponibilidad')
<x-account-layout :title="$heading">
  <section class="account-card account-narrow"><h1>{{ $heading }}</h1><p>{{ $message }}</p><a class="account-button secondary" href="{{ route('availability.edit') }}">Recargar mis horarios</a></section>
</x-account-layout>
