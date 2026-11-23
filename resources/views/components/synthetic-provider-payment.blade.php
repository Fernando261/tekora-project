@props(['summary' => null])
@if($summary)
<section class="account-card" aria-labelledby="synthetic-provider-payment-{{ $summary['reservation_id'] }}" data-b32-provider-payment>
  <span class="account-eyebrow">Checkout local · Sin cobros reales</span>
  <h2 id="synthetic-provider-payment-{{ $summary['reservation_id'] }}">Estado de pago de prueba</h2>
  <p role="status"><strong>{{ $summary['label'] }}</strong></p>
  <p class="account-help">Reserva #{{ $summary['reservation_id'] }} · Cotización vinculada. Este resultado es ficticio y no representa dinero recibido por el banco.</p>
  <dl class="inquiry-participants">
    <div><dt>Importe acordado</dt><dd>{{ \App\Payments\WebMoney::format($summary['amount_cents']) }}</dd></div>
    <div><dt>Método</dt><dd>{{ $summary['method'] === 'card' ? 'Tarjeta ficticia' : ($summary['method'] === 'cash' ? 'Efectivo' : 'No seleccionado') }}</dd></div>
    @if($summary['reference'])<div><dt>Referencia de prueba</dt><dd>{{ $summary['reference'] }}</dd></div>@endif
    @if($summary['attempted_at'])<div><dt>Selección registrada (UTC)</dt><dd><time>{{ $summary['attempted_at'] }}</time></dd></div>@endif
    @if($summary['received_at'])<div><dt>Recepción registrada (UTC)</dt><dd><time>{{ $summary['received_at'] }}</time></dd></div>@endif
  </dl>
  @if($summary['can_receive_cash'])
    <a class="account-button secondary" href="{{ route('checkout.local.cash', ['reservation' => $summary['reservation_id']]) }}">Consultar recepción de efectivo</a>
  @endif
  @if(!$summary['reservation_active'])
    <p class="account-note">La reserva ya no está activa. El resultado histórico no implica devolución, reembolso ni liquidación.</p>
  @endif
  <p class="account-help">Comprobante interno de demostración; no es factura ni comprobante bancario o fiscal.</p>
</section>
@endif
