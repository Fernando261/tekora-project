@props(['reservation'])
@php($effective = $reservation->effectiveStatus())
<span class="reservation-status reservation-{{ $effective->value }}">{{ $effective === \App\Enums\ReservationStatus::Requested && $reservation->expired() ? 'Propuesta expirada' : $effective->label() }}</span>
