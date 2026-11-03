@props(['reservation'])
<span class="reservation-status reservation-{{ $reservation->status->value }}">{{ $reservation->expired() ? 'Propuesta expirada' : $reservation->status->label() }}</span>
