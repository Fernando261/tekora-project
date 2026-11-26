<x-account-layout :operations="true" title="Recepción de efectivo">
  @php($officialCheckout = $officialCheckout ?? false)
  @push('styles')
    @vite(['resources/css/tekora/checkout-demo.css', 'resources/css/tekora/checkout-integrated.css'])
  @endpush
  <div class="tk-checkout b32-checkout co-provider-page">
    <a href="{{ route('reservations.show', $reservation) }}" class="co-back-link b32-back">← Volver a la reserva</a>
    <div class="co-heading b32-heading">
      <div>
        <p class="co-eyebrow">RECEPCIÓN DE EFECTIVO · TEKORA</p>
        <h1>La otra parte de la experiencia<span>.</span></h1>
      </div>
      <p class="b32-heading-aside">Vista privada del prestador.<small>Checkout integrado · {{ $officialCheckout ? 'Procesamiento simulado' : 'Entorno local de pruebas' }}</small></p>
    </div>
    <section class="co-receiver-panel b32-provider-panel" aria-labelledby="b32-cash-title">
      <div class="co-shell-title"><span class="co-mini-mark" aria-hidden="true">T</span> CHECKOUT <span> / PRESTADOR</span></div>
      <h2 id="b32-cash-title">Recepción de efectivo</h2>
      <p class="b32-result-lead">Reserva #{{ $reservation }} · Solo el prestador autorizado puede registrar este resultado.</p>

      @if(!$attempt)
        <div class="b32-notice" role="status">
          <span aria-hidden="true">◇</span> El cliente todavía no ha seleccionado efectivo para esta reserva.
        </div>
      @else
        <div class="co-receiver-item b32-provider-item">
          <div class="b32-provider-status">
            <span class="b32-result-symbol {{ $attempt['status'] === 'cash_received' ? 'b32-result-success' : 'b32-result-awaiting' }}" aria-hidden="true">{{ $attempt['status'] === 'cash_received' ? '✓' : '◇' }}</span>
            <div>
              <p class="co-eyebrow">ESTADO DE LA RECEPCIÓN</p>
              <h3>{{ $attempt['status'] === 'awaiting_cash' ? 'Pendiente de recepción' : 'Recepción de prueba registrada' }}</h3>
            </div>
          </div>
          <section class="co-receipt b32-provider-receipt" aria-label="Detalle de la operación">
            <span>COMPROBANTE INTERNO · TEKORA</span>
            <strong>{{ \App\Payments\WebMoney::format((int) $attempt['amount_cents']) }}</strong>
            <dl>
              <div><dt>Método</dt><dd>Efectivo</dd></div>
              <div><dt>Referencia</dt><dd>{{ $attempt['reference'] }}</dd></div>
              <div><dt>Estado</dt><dd>{{ $attempt['status'] === 'awaiting_cash' ? 'Pendiente de recepción' : 'Recepción registrada' }}</dd></div>
            </dl>
            <small>Resultado interno de prueba; no constituye un recibo bancario ni fiscal.</small>
          </section>
          @if($attempt['status'] === 'awaiting_cash' && $canReceiveCash)
            <form method="POST" action="{{ route($officialCheckout ? 'checkout.integrated.cash.receive' : 'checkout.local.cash.receive', $reservation) }}">
              @csrf
              <button type="submit" class="co-primary b32-primary">Registrar recepción de efectivo <span aria-hidden="true">→</span></button>
            </form>
          @else
            @if($attempt['status'] === 'awaiting_cash')
              <p class="b32-notice" role="status">El estado del horario impide registrar la recepción. Consulta la reserva y su historial.</p>
            @endif
            <a href="{{ route('reservations.show', $reservation) }}" class="co-secondary b32-secondary">Volver al seguimiento de la reserva</a>
          @endif
        </div>
      @endif
      <p class="b32-legal">{{ $officialCheckout ? 'La recepción se guarda como resultado simulado de Tekora.' : 'La recepción se registra exclusivamente en la SQLite sintética local.' }} No acredita cobros reales, no liquida al prestador y no confirma automáticamente la reserva.</p>
    </section>
  </div>
</x-account-layout>
