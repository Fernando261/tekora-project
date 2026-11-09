{{-- La respuesta de error no consulta la bandeja ni los contadores. --}}
<x-ops-error-layout title="Revisar notificaciones"><section class="account-card"><h1>No se pudo completar la operación</h1><p class="account-alert is-error" role="alert">{{ $message }}</p><a class="account-button" href="{{ route('notifications.index') }}">Volver a notificaciones</a></section></x-ops-error-layout>
