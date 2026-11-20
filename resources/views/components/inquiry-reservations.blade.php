@props(['inquiry', 'journey' => null, 'journeyReservation' => null])
@php($recentReservations = $inquiry->reservations()->orderByDesc('id')->limit(5)->get())
<section class="account-card" id="reservas" aria-labelledby="inquiry-reservations-title"><h2 id="inquiry-reservations-title">Reservas de esta solicitud</h2>
  <p class="account-help">Horario propuesto ≠ Reserva confirmada. Duración: 60 minutos. No se realizan pagos.</p>
  @if($journeyReservation)
    <div class="cj-reservation" id="decision-reserva-{{ $journeyReservation->id }}" tabindex="-1">
      <div class="cj-section-title"><h3>Tu horario actual</h3><x-reservation-status :reservation="$journeyReservation" /></div>
      <p><strong>{{ $journeyReservation->local_date }} · {{ $journeyReservation->localTime() }}–{{ $journeyReservation->localEndTime() }}</strong><br><span class="account-help">{{ $journeyReservation->timezone }}</span></p>
      <x-reservation-hold :reservation="$journeyReservation" />
      @if($journey['reservation']['status'] === 'held')<p class="account-note">Confirma antes del vencimiento. Este paso confirma tu horario; Tekora todavía no realiza cobros.</p>@endif
      <x-reservation-actions :reservation="$journeyReservation" :journey-return="true" :allowed-actions="$journey['reservation']['actions']" />
      @if($journey['viewer_role'] === 'provider'
          && \App\Http\Controllers\LocalIntegratedCheckoutController::permitsSyntheticRequest(request())
          && $journeyReservation->providerProfile->user_id === auth('web')->id())
        <p><a class="account-button secondary" href="{{ route('checkout.local.cash', $journeyReservation->id) }}">Consultar recepción de efectivo</a></p>
      @endif
      @if(\App\Http\Controllers\LocalIntegratedCheckoutController::permitsSyntheticRequest(request())
          && $journey['viewer_role'] === 'client' && auth('web')->id() === $journeyReservation->client_user_id
          && in_array('commit', $journey['reservation']['actions'], true)
          && $journeyReservation->effectiveStatus() === \App\Enums\ReservationStatus::Held
          && $journeyReservation->hold?->remainingSeconds() > 0)
        @php($testSelectionSaved = ($journey['confirmation_readiness']['synthetic_web_requirement'] ?? null) === 'satisfied')
        <aside class="account-note" aria-labelledby="checkout-demo-invitation-title" data-checkout-demo-invitation>
          <h3 id="checkout-demo-invitation-title">{{ $testSelectionSaved ? 'Selección de prueba registrada' : 'Checkout de prueba' }}</h3>
          <p>{{ $testSelectionSaved ? 'Confirma la reserva desde esta solicitud antes del vencimiento.' : 'Consulta el importe acordado y registra una selección ficticia antes de confirmar la reserva.' }}</p>
          <a class="account-button secondary" href="{{ route('checkout.local.show', $journeyReservation->id) }}" rel="noreferrer" referrerpolicy="no-referrer">{{ $testSelectionSaved ? 'Consultar resultado de prueba' : 'Elegir método de pago de prueba' }}</a>
        </aside>
      @endif
      <details class="cj-event-details"><summary>Historial de este horario</summary><ol class="tk-ops-timeline">@foreach($journeyReservation->events->sortBy('version') as $event)<li><strong>{{ \App\Enums\ReservationStatus::from($event->to_status)->label() }}</strong><span>{{ $event->actor?->name ?? 'Sistema' }} · <x-inquiry-time :at="\Carbon\CarbonImmutable::parse($event->occurred_at_utc, 'UTC')" /></span></li>@endforeach</ol></details>
    </div>
  @endif
  @if($recentReservations->isEmpty())<p>Todavía no hay propuestas de horario.</p>@else
    <ul class="inquiry-bookings">@foreach($recentReservations as $reservation)@can('view', $reservation)<li>
      <div class="inquiry-booking-heading"><a href="{{ route('reservations.show', $reservation) }}">Consultar reserva #{{ $reservation->id }}</a><x-reservation-status :reservation="$reservation" /></div>
      <p class="account-help">{{ $reservation->local_date }} · {{ $reservation->localTime() }}–{{ $reservation->localEndTime() }} · {{ $reservation->timezone }}</p>
    </li>@endcan @endforeach</ul>
  @endif
  @if($journey ? $journey['commercial']['can_propose'] : ($inquiry->status === \App\Enums\InquiryStatus::Accepted && ! $inquiry->reservations()->active()->exists()))
    @can('propose', [\App\Models\ServiceReservation::class, $inquiry])<p><a class="account-button secondary" href="{{ route('reservations.create', $journey ? ['inquiry' => $inquiry->id, 'follow' => 1] : $inquiry) }}">Proponer un horario</a></p>@endcan
  @endif
</section>

@if($journey)
<section class="account-card" id="cierre" aria-labelledby="journey-closure-title">
  <span class="account-eyebrow">Después del servicio</span><h2 id="journey-closure-title">Cierre y experiencia</h2>
  @if($journey['closure']['explicit_service_completed'])
    <p>El prestador registró que el servicio se realizó. {{ $journey['closure']['inquiry_completed'] ? 'La solicitud está finalizada.' : 'La solicitud conserva su seguimiento hasta el cierre operativo.' }}</p>
  @else<p>La realización se registra explícitamente después del horario. Una cotización aceptada o el paso del tiempo no completan el servicio.</p>@endif
  @if($journey['closure']['can_close'])<p><a href="#acciones-solicitud">Ir a finalizar la solicitud</a></p>@endif
  @if($journey['review']['reservation_id'])
    @php($reviewReservation = $inquiry->reservations()->findOrFail($journey['review']['reservation_id']))
    <x-reservation-review :reservation="$reviewReservation" :journey-return="true" />
    @if($journey['review']['state'] === 'withdrawn')<p class="account-help">Tu reseña está retirada. Se conserva tu registro privado y no puede reemplazarse.</p>@elseif($journey['review']['state'] === 'published')<p class="account-help">Ya compartiste tu experiencia. Puedes consultar tu reseña y su estado.</p>@endif
  @elseif($journey['viewer_role'] === 'client')<p class="account-help">Podrás compartir tu experiencia cuando el servicio figure como realizado.</p>@else<p class="account-help">El cliente podrá compartir su experiencia conforme a las reglas de reseñas.</p>@endif
</section>
@endif
