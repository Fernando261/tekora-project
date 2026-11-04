<x-account-layout :title="'Reserva #'.$reservation->id">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reservations.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Seguimiento privado</span><h1>Reserva #{{ $reservation->id }}</h1><p>{{ $reservation->inquiry->service_title }}</p></header>
  <div class="account-grid">
    <section class="account-card"><x-reservation-status :reservation="$reservation" />
      <h2>Horario de la reserva</h2><x-reservation-schedule :reservation="$reservation" />
      <p>Cliente: {{ $reservation->client->name }}<br>Prestador: {{ $reservation->providerProfile->display_name }}</p>
      @if($reservation->status === \App\Enums\ReservationStatus::Requested)
        <p class="account-note">Este horario es una propuesta. No es una reserva confirmada. Requiere confirmación explícita del prestador.</p>
        @if($reservation->expired())<p class="account-alert is-error">La propuesta expiró. Ya no puede confirmarse; cancélala o recházala para proponer un horario nuevo.</p>@endif
      @elseif($reservation->status === \App\Enums\ReservationStatus::Confirmed)
        <p class="account-note">El prestador confirmó este horario. Los cambios posteriores en su disponibilidad no desplazan esta reserva.</p>
      @else
        <p class="account-note">Este registro es histórico y no vuelve a activarse. Si la solicitud sigue aceptada, el cliente puede preparar otra propuesta.</p>
      @endif
      <p class="account-help">Duración: 60 minutos. No se han realizado pagos desde esta plataforma.</p>
      <x-reservation-actions :reservation="$reservation" />
      <x-reservation-review :reservation="$reservation" />
      @if(! $reservation->status->active() && $reservation->inquiry->status === \App\Enums\InquiryStatus::Accepted)
        @can('propose', [\App\Models\ServiceReservation::class, $reservation->inquiry])<p><a href="{{ route('reservations.create', $reservation->service_inquiry_id) }}">Proponer otro horario</a></p>@endcan
      @endif
      <p><a href="{{ route('inquiries.show', $reservation->service_inquiry_id) }}">Consultar solicitud y conversación</a></p>
    </section>
    <section class="account-card"><h2>Historial de la reserva</h2><p class="account-help">Fechas de decisiones expresadas en UTC. El horario del servicio aparece en su zona original.</p>
      <ol class="reservation-history">@foreach($reservation->events->sortBy('version') as $event)<li>
        <strong>{{ \App\Enums\ReservationStatus::from($event->to_status)->label() }}</strong>
        <span>{{ $event->actor->name }} · <time>{{ $event->occurred_at_utc }} UTC</time></span>
        @if($event->reason === 'inquiry_completed')<span>Decisión conjunta al finalizar la solicitud.</span>@endif
      </li>@endforeach</ol>
    </section>
  </div>
  <p><a href="{{ route('reservations.index') }}">Volver a Mis reservas</a></p>
</x-account-layout>
