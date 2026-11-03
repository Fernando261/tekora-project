@props(['profile'])
@php($schedule = app(\App\Services\ProviderAvailability::class)->publicFor($profile))
<section class="account-card provider-availability" aria-label="Horarios orientativos del prestador">
  <h2>Horarios del prestador</h2>
  @if($schedule['unavailable'] ?? false)
    <p>Horario temporalmente no disponible. Consulta al prestador.</p>
  @elseif(!$schedule)
    <p>Horario no publicado</p>
  @else
    <p><strong>Zona horaria: {{ $schedule['timezone'] }}</strong></p>
    <dl class="availability-summary">@foreach(\App\Enums\AvailabilityDay::cases() as $day)<div><dt>{{ $day->label() }}</dt><dd>@forelse($schedule['days']->get($day->value, collect()) as $interval)<span>{{ \App\Models\ProviderWeeklyInterval::civilTime($interval->start_minute) }}–{{ \App\Models\ProviderWeeklyInterval::civilTime($interval->end_minute) }}</span>@empty<span>Sin atención</span>@endforelse</dd></div>@endforeach</dl>
    <h3>Fechas sin atención</h3>
    @if($schedule['blocked_dates']->isEmpty())<p>No hay fechas vigentes bloqueadas publicadas.</p>@else<ul class="availability-blocks">@foreach($schedule['blocked_dates'] as $date)<li>{{ implode('/', array_reverse(explode('-', $date))) }}</li>@endforeach</ul>@endif
  @endif
  <p class="account-note">Horarios orientativos, sujetos a confirmación con el prestador</p>
</section>
