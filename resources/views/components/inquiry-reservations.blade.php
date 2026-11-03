@props(['inquiry'])
@php($recentReservations = $inquiry->reservations()->orderByDesc('id')->limit(5)->get())
<section class="account-card" id="reservas"><h2>Reservas de esta solicitud</h2>
  <p class="account-help">Horario propuesto ≠ Reserva confirmada. Duración: 60 minutos. No se realizan pagos.</p>
  @if($recentReservations->isEmpty())<p>Todavía no hay propuestas de horario.</p>@else
    <ul>@foreach($recentReservations as $reservation)@can('view', $reservation)<li><a href="{{ route('reservations.show', $reservation) }}">Reserva #{{ $reservation->id }} · {{ $reservation->status->label() }} · {{ $reservation->local_date }} {{ $reservation->localTime() }} · {{ $reservation->timezone }}</a></li>@endcan @endforeach</ul>
  @endif
  @if($inquiry->status === \App\Enums\InquiryStatus::Accepted && ! $inquiry->reservations()->active()->exists())
    @can('propose', [\App\Models\ServiceReservation::class, $inquiry])<p><a class="account-button secondary" href="{{ route('reservations.create', $inquiry) }}">Proponer un horario</a></p>@endcan
  @endif
</section>
