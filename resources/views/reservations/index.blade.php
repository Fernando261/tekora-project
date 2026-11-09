<x-account-layout :operations="true" title="Mis reservas">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reservations.css') }}">@endpush
  <x-ops-heading variant="time"><span class="account-eyebrow">Seguimiento privado</span><h1>Mis reservas</h1><p>Consulta tus propuestas como cliente y las reservas de tus servicios como prestador. Cada horario requiere confirmación explícita.</p></x-ops-heading>
  <p class="account-note">Horario propuesto ≠ Reserva confirmada. Las reservas duran 60 minutos. No se han realizado pagos desde esta plataforma.</p>
  @if($reservations->isEmpty())
    <section class="account-card"><h2>Todavía no tienes reservas</h2><p>Las propuestas se preparan desde una solicitud aceptada, si el prestador ha publicado disponibilidad.</p>
      @can(\App\Enums\Permission::ManageOwnInquiries->value)<p><a href="{{ route('inquiries.index') }}">Consultar mis solicitudes</a></p>@endcan
      @can(\App\Enums\Permission::ManageReceivedInquiries->value)<p><a href="{{ route('inquiries.received') }}">Consultar solicitudes recibidas</a></p>@endcan
    </section>
  @else
    <div class="reservation-list">@foreach($reservations as $reservation)<article data-ops-card class="account-card reservation-card">
      <x-reservation-status :reservation="$reservation" />
      <h2>{{ $reservation->inquiry->service_title }}</h2>
      <x-reservation-schedule :reservation="$reservation" />
      <p>Cliente: {{ $reservation->client->name }}<br>Prestador: {{ $reservation->providerProfile->display_name }}</p>
      <a class="account-button secondary" href="{{ route('reservations.show', $reservation) }}">Consultar reserva #{{ $reservation->id }}</a>
    </article>@endforeach</div>
    <x-catalog-pagination :paginator="$reservations" />
  @endif
</x-account-layout>
