<x-account-layout :operations="true" title="Finalizar contratación">
  @push('styles')
    <style>
      .b32-wrap{max-width:940px;margin:2rem auto;display:grid;gap:1.5rem}.b32-card{padding:2rem;border-radius:1.2rem;background:var(--surface,#101c2e);border:1px solid rgba(120,140,165,.25)}.b32-row{display:flex;justify-content:space-between;gap:1rem;align-items:center}.b32-total{font-size:2rem;font-weight:800}.b32-controls{display:grid;gap:1rem}.b32-controls input,.b32-controls select{width:100%;min-height:46px;padding:.65rem;border-radius:.6rem}.b32-label{display:block;margin-bottom:.35rem}.b32-note{font-size:.9rem;opacity:.82}.b32-methods{display:flex;gap:1.25rem;flex-wrap:wrap}.b32-grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem}@media(max-width:650px){.b32-grid{grid-template-columns:1fr}.b32-card{padding:1.1rem}} 
    </style>
  @endpush
  <div class="b32-wrap">
    <a href="{{ route('reservations.show', $reservation) }}">← Volver a mi reserva</a>
    <header><span class="account-eyebrow">Tekora · Entorno local de pruebas</span><h1>Finaliza tu contratación</h1><p>Confirma tu método de pago para {{ $context['service']['title'] }}.</p></header>
    <section class="b32-card" aria-label="Resumen de contratación">
      <div class="b32-row"><div><strong>{{ $context['service']['title'] }}</strong><p>Prestador: {{ $context['provider']['display_name'] }}</p></div><div class="b32-total">{{ $formattedAmount }}</div></div>
      <p>MXN · Comisión del 15 % incluida · Cotización acordada</p>
      <p class="b32-note">Horario retenido: {{ $context['hold']['remaining_seconds'] ?? 0 }} segundos restantes al cargar esta pantalla. La operación vuelve a comprobar el tiempo en el servidor.</p>
    </section>
    @if($attempt)
      <section class="b32-card" aria-live="polite">
        <h2>Resultado de tu solicitud de pago</h2>
        <p><strong>@switch($attempt['status'])
          @case('test_approved') Tarjeta aprobada @break
          @case('declined') Tarjeta rechazada @break
          @case('temporary_failure') No fue posible procesar la tarjeta @break
          @case('cash_received') Efectivo recibido por el prestador @break
          @default Efectivo pendiente de recepción
        @endswitch</strong></p>
        <p>Referencia: {{ $attempt['reference'] }}</p>
        <p class="b32-note">Resultado compartido únicamente mediante la SQLite sintética de esta presentación; no es una operación bancaria ni confirma automáticamente el horario.</p>
        <a class="account-button secondary" href="{{ route('reservations.show', $reservation) }}">Continuar con mi reserva</a>
      </section>
    @endif
    @if($context['future_payment']['commercial_eligible'] && ($context['hold']['remaining_seconds'] ?? 0) > 0 && (!$attempt || in_array($attempt['status'], ['declined', 'temporary_failure'], true)))
      <section class="b32-card">
        <h2>Selecciona cómo pagar</h2>
        <form class="b32-controls" method="POST" action="{{ route('checkout.local.submit', $reservation) }}" autocomplete="off" data-b32-form>
          @csrf
          <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
          <fieldset class="b32-methods"><legend>Forma de pago</legend><label><input type="radio" name="method" value="card" checked data-b32-method> Tarjeta</label><label><input type="radio" name="method" value="cash" data-b32-method> Efectivo</label></fieldset>
          <div data-b32-card>
            <label class="b32-label" for="b32-number">Número de tarjeta de prueba</label>
            <select name="card_number" id="b32-number"><option value="4242424242424242">4242 4242 4242 4242 · Aprobada</option><option value="4000000000000002">4000 0000 0000 0002 · Rechazada</option><option value="4000000000000119">4000 0000 0000 0119 · Error temporal</option></select>
            <div class="b32-grid"><div><label class="b32-label" for="b32-expiry">Vencimiento de prueba</label><input id="b32-expiry" name="expiry" value="12/30" readonly></div><div><label class="b32-label" for="b32-cvv">CVV de prueba</label><input id="b32-cvv" name="cvv" value="123" readonly></div></div>
          </div>
          <p class="b32-note">Selecciona una de las tarjetas ficticias de prueba. No introduzcas información bancaria real. Este checkout no está conectado a pasarelas.</p>
          @if($errors->any())<p role="alert">Revisa los datos de prueba seleccionados.</p>@endif
          <button class="account-button" type="submit">Confirmar método de pago</button>
        </form>
      </section>
    @else
      <section class="account-note" role="status">La retención ya no está disponible para continuar al pago. Vuelve a la reserva y revisa su estado.</section>
    @endif
    <p class="b32-note">Este entorno privado representa el flujo del producto. Los resultados son de prueba y no acreditan dinero, no generan recibos fiscales y no sustituyen la confirmación manual de la reserva.</p>
  </div>
  @push('scripts')<script defer src="{{ asset('js/tekora/b32-checkout.js') }}"></script>@endpush
</x-account-layout>
