@props(['reservation'])
@if($reservation->quote_id)
  @once @push('styles')<link rel="stylesheet" href="{{ asset('css/reservation-holds.css') }}">@endpush @endonce
  @php($hold = $reservation->hold)
  <section class="hold-panel" aria-labelledby="hold-title">
    <span class="account-eyebrow">Cotización y horario · Sin cobros</span><h2 id="hold-title">{{ $hold ? match($hold->state) { 'held' => $hold->remainingSeconds() > 0 ? 'Horario reservado temporalmente' : 'Retención vencida', 'committed' => 'Retención convertida en reserva', 'released' => 'Retención liberada', default => 'Retención vencida' } : 'Propuesta con cotización aceptada' }}</h2>
    <a href="{{ route('quotes.show', $reservation->quote_id) }}">Consultar cotización aceptada y desglose</a>
    @if(!$hold)<p>El plazo comienza cuando el prestador acepta esta propuesta concreta; ni la solicitud ni la cotización inician la retención.</p>
    @elseif($hold->state === 'held' && $hold->remainingSeconds() > 0)
      <p>El horario es exclusivo durante 900 segundos desde la aceptación del prestador.</p>
      <p class="hold-countdown" data-hold-countdown data-remaining-ms="{{ max(0, intdiv($hold->expires_at_us - \App\Support\BookingClock::observedMicros(), 1000)) }}"><span>Tiempo restante orientativo</span><strong data-hold-time>{{ gmdate('i:s', $hold->remainingSeconds()) }}</strong><span data-hold-expiry role="status"></span></p>
      <p>Vence: <time datetime="{{ \App\Support\BookingClock::date($hold->expires_at_us)->toISOString() }}">{{ \App\Support\BookingClock::date($hold->expires_at_us)->setTimezone($reservation->timezone)->format('d/m/Y H:i:s') }}</time> · {{ $reservation->timezone }}</p>
      <p class="account-help">El servidor valida la vigencia. Confirma antes del vencimiento y antes del inicio del servicio; actualizar o cambiar de dispositivo no amplía el plazo.</p>
      @once @push('scripts')<script src="{{ asset('js/reservation-hold.js') }}" defer></script>@endpush @endonce
    @elseif($hold->state === 'expired' || ($hold->state === 'held' && $hold->remainingSeconds() === 0))
      <p>Terminó el plazo. No se confirmó la reserva. Puedes preparar otra propuesta; esta retención no se renueva.</p>
    @elseif($hold->state === 'released')<p>La cancelación liberó el horario. Este registro no vuelve a activarse.</p>
    @else<p>La reserva quedó confirmada por el cliente. Esta aceptación no representa un pago.</p>@endif
    @if($hold)
      <h3>Historial de la retención</h3><ol class="hold-history">@foreach($hold->events->sortBy('version') as $event)<li><strong>{{ match($event->to_state) { 'held' => 'Propuesta aceptada y horario retenido', 'committed' => 'Reserva confirmada por el cliente', 'released' => 'Cancelada y liberada', default => 'Vencimiento automático' } }}</strong><span>{{ $event->actor?->name ?? 'Sistema' }} · {{ \App\Support\BookingClock::date((int) $event->occurred_at_us)->format('d/m/Y H:i:s') }} UTC</span></li>@endforeach</ol>
    @endif
  </section>
@endif
