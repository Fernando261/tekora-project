@props(['reservation', 'journeyReturn' => false, 'allowedActions' => null])
@php
  $state = $reservation->effectiveStatus(); $nowUtc = now('UTC')->format('Y-m-d H:i:s'); $actions = [];
  if ($state === \App\Enums\ReservationStatus::Requested) {
      if ($reservation->starts_at_utc > $nowUtc) $actions['confirm'] = $reservation->quote_id ? 'Aceptar propuesta y retener 15 minutos' : 'Confirmar reserva';
      $actions['reject'] = 'Rechazar propuesta'; $actions['cancel'] = 'Cancelar propuesta';
  }
  if ($state === \App\Enums\ReservationStatus::Held) {
      if ($reservation->starts_at_utc > $nowUtc) $actions['commit'] = 'Confirmar definitivamente mi reserva';
      $actions['cancel'] = 'Cancelar y liberar horario';
  }
  if ($state === \App\Enums\ReservationStatus::Confirmed) {
      if ($reservation->starts_at_utc > $nowUtc) $actions['cancel'] = 'Cancelar reserva';
      if ($reservation->ends_at_utc <= $nowUtc) $actions['complete'] = 'Marcar como realizado';
  }
  if ($allowedActions !== null) $actions = array_intersect_key($actions, array_flip($allowedActions));
  $officialCheckout = \App\Payments\IntegratedCheckout::requiresChoice($reservation);
  $needsCheckout = (isset($actions['commit']) || in_array('checkout', $allowedActions ?? [], true))
      && $reservation->quote_id && auth('web')->id() === $reservation->client_user_id
      && ($officialCheckout
          ? ! app(\App\Payments\IntegratedCheckout::class)->permitsCommit($reservation)
          : (\App\Http\Controllers\LocalIntegratedCheckoutController::permitsSyntheticRequest(request())
              && ! app(\App\Payments\LocalCheckoutAttemptStore::class)->permitsCommit($reservation)));
  if ($needsCheckout) unset($actions['commit']);
@endphp
<div class="reservation-actions">@foreach($actions as $action => $label)@can($action, $reservation)
  <form method="POST" action="{{ route('reservations.'.$action, $reservation) }}">@csrf @if($journeyReturn)<input type="hidden" name="_journey" value="1">@endif @method('PATCH')<input type="hidden" name="version" value="{{ $reservation->version }}">@if($reservation->quote_id)<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif<button @if($action === 'commit') data-hold-confirm @endif class="account-button {{ in_array($action, ['cancel','reject']) ? 'secondary' : '' }}" type="submit">{{ $label }}</button></form>
@endcan @endforeach
  @if($needsCheckout)
    <div class="account-note" role="status" data-b32-checkout-required>
      Antes de confirmar definitivamente tu reserva, elige un método de pago de prueba.
      @unless($journeyReturn)
        <a href="{{ route($officialCheckout ? 'checkout.integrated.show' : 'checkout.local.show', $reservation) }}" rel="noreferrer" referrerpolicy="no-referrer">Elegir método de pago de prueba</a>
      @endunless
    </div>
  @endif
</div>
