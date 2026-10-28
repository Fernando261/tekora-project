<x-account-layout title="Solicitar servicio">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/inquiries.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Experiencia de cliente</span><h1>Solicitar servicio</h1><p>Cuéntale al prestador qué necesitas. Podrás seguir su respuesta desde Mis solicitudes.</p></header>
  <section class="account-card account-narrow">
    <h2>{{ $service->title }}</h2><p>Prestador: {{ $service->providerProfile->display_name }}</p>
    <p class="account-note">Esta solicitud no crea una reserva ni un contrato automático. No incluye pagos ni confirmación de disponibilidad.</p>
    @php $message = old('message'); @endphp
    <form class="account-form" method="POST" action="{{ route('inquiries.store', $service) }}">
      @csrf
      <label for="message">¿Qué necesitas?</label>
      <textarea id="message" name="message" rows="7" minlength="10" maxlength="2000" required aria-describedby="message-help" @error('message') aria-invalid="true" @enderror>{{ is_string($message) ? $message : '' }}</textarea>
      <p id="message-help" class="account-help">Entre 10 y 2000 caracteres. Describe tu necesidad sin incluir correo, teléfono, direcciones, contraseñas ni datos de pago. No se admiten archivos adjuntos.</p>
      <button class="account-button" type="submit">Confirmar y enviar solicitud</button>
      <a href="{{ route('catalog.show', $service) }}">Volver al servicio</a>
    </form>
  </section>
</x-account-layout>
