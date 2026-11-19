<x-account-layout :operations="true" title="Recepción de efectivo">
  <div class="account-card" style="max-width:740px;margin:2rem auto;padding:2rem">
    <a href="{{ route('reservations.show', $reservation) }}">← Volver a la reserva</a>
    <p class="account-eyebrow" style="margin-top:1.5rem">Tekora · Entorno local de pruebas</p>
    <h1>Recepción de efectivo</h1>
    <p>Este registro corresponde exclusivamente al recorrido de prueba de la reserva #{{ $reservation }}.</p>
    @if(!$attempt)
      <p class="account-note">El cliente todavía no ha seleccionado efectivo para esta reserva.</p>
    @else
      <div class="account-note">
        <p><strong>Importe de prueba:</strong> {{ \App\Payments\WebMoney::format((int) $attempt['amount_cents']) }}</p>
        <p><strong>Referencia:</strong> {{ $attempt['reference'] }}</p>
        <p><strong>Estado:</strong> {{ $attempt['status'] === 'awaiting_cash' ? 'Pendiente de recepción' : 'Recepción de prueba registrada' }}</p>
      </div>
      @if($attempt['status'] === 'awaiting_cash')
        <form method="POST" action="{{ route('checkout.local.cash.receive', $reservation) }}">
          @csrf
          <button type="submit" class="account-button">Registrar recepción de efectivo</button>
        </form>
      @endif
    @endif
    <p class="account-help" style="margin-top:1.5rem">Solo registra un resultado en la SQLite sintética; no acredita dinero comercial, no liquida al prestador y no confirma la reserva B2.</p>
  </div>
</x-account-layout>
