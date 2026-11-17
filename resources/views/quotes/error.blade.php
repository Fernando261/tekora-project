<x-account-layout :operations="true" title="Cotización no procesada">
  <section class="account-card account-narrow"><h1>No pudimos completar la operación</h1><p role="alert">{{ $message }}</p><p>Si había una operación en curso, conserva el formulario y su clave de reintento. Puedes consultar primero su resultado.</p><a class="account-button" href="{{ route('inquiries.index') }}">Mis solicitudes</a><a href="{{ route('provider-services.index') }}">Mis servicios</a></section>
</x-account-layout>
