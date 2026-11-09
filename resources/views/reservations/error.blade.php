{{-- Esta respuesta no consulta contadores ni navegación de base de datos durante un bloqueo. --}}
<x-ops-error-layout title="Revisar reserva">
  <section class="account-card account-narrow"><h1>No se pudo completar la operación</h1><p class="account-alert is-error" role="alert">{{ $message }}</p><p>Consulta el estado actual antes de volver a enviar. Un horario propuesto necesita confirmación del prestador.</p><a class="account-button" href="{{ route('account.index') }}">Volver a mi cuenta</a></section>
</x-ops-error-layout>
