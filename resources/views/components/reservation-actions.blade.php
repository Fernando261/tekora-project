@props(['reservation'])
@php
  $state = $reservation->status; $nowUtc = now('UTC')->format('Y-m-d H:i:s'); $actions = [];
  if ($state === \App\Enums\ReservationStatus::Requested) {
      if ($reservation->starts_at_utc > $nowUtc) $actions['confirm'] = 'Confirmar reserva';
      $actions['reject'] = 'Rechazar propuesta'; $actions['cancel'] = 'Cancelar propuesta';
  }
  if ($state === \App\Enums\ReservationStatus::Confirmed) {
      if ($reservation->starts_at_utc > $nowUtc) $actions['cancel'] = 'Cancelar reserva';
      if ($reservation->ends_at_utc <= $nowUtc) $actions['complete'] = 'Marcar como realizado';
  }
@endphp
<div class="reservation-actions">@foreach($actions as $action => $label)@can($action, $reservation)
  <form method="POST" action="{{ route('reservations.'.$action, $reservation) }}">@csrf @method('PATCH')<input type="hidden" name="version" value="{{ $reservation->version }}"><button class="account-button {{ in_array($action, ['cancel','reject']) ? 'secondary' : '' }}" type="submit">{{ $label }}</button></form>
@endcan @endforeach</div>
