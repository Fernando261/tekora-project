<x-account-layout :operations="true" title="Proponer un horario">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reservations.css') }}"><link rel="stylesheet" href="{{ asset('css/availability.css') }}">@endpush
  <x-ops-heading variant="time"><span class="account-eyebrow">Solicitud aceptada #{{ $inquiry->id }}</span><h1>Proponer un horario</h1><p>{{ $inquiry->service_title }} · {{ $inquiry->service->providerProfile->display_name }}</p></x-ops-heading>
  <div class="account-grid">
    <section class="account-card"><h2>Tu propuesta de 60 minutos</h2>
      <p class="account-note">Elige fecha y hora en la zona <strong>{{ $setting->timezone }}</strong>, aunque te encuentres en otra zona. El prestador deberá confirmar el horario. No se realiza ningún pago.</p>
      <form class="account-form reservation-form" method="POST" action="{{ route('reservations.store', $inquiry) }}">
        @csrf @if($journeyReturn ?? false)<input type="hidden" name="_journey" value="1">@endif
        <input type="hidden" name="idempotency_key" value="{{ is_string(old('idempotency_key')) ? old('idempotency_key') : $key }}">
        <input type="hidden" name="availability_revision" value="{{ $setting->revision }}">
        @if($commercial)
          <section class="account-note"><strong>Retención de 15 minutos</strong><p>Elige una cotización que ya hayas aceptado. El prestador acepta este horario concreto y entonces tendrás 15 minutos para confirmarlo definitivamente. No se realizan pagos.</p></section>
          @if(count($commercial['accepted_quotes']) === 0)<p role="status">Primero consulta y acepta una cotización desde <a href="{{ $commercial['quotes_web_url'] }}">la solicitud</a>.</p>@else
          <label for="reservation-quote">Cotización aceptada</label><select id="reservation-quote" name="quote_id" required><option value="">Selecciona un importe aceptado</option>@foreach($commercial['accepted_quotes'] as $quote)<option value="{{ $quote->id }}" @selected(old('quote_id') === $quote->id)>{{ \App\Payments\WebMoney::format($quote->total_cents) }} · precio v{{ $quote->price_version }} · {{ substr($quote->id, 0, 8) }}</option>@endforeach</select>
          @endif
        @endif
        <label for="reservation-date">Fecha en {{ $setting->timezone }}</label>
        <input id="reservation-date" name="date" type="date" min="{{ $minDate }}" max="{{ $maxDate }}" value="{{ is_string(old('date')) ? old('date') : '' }}" required @error('date') aria-invalid="true" @enderror>
        <label for="reservation-time">Hora de inicio en {{ $setting->timezone }}</label>
        <input id="reservation-time" name="time" type="time" step="60" value="{{ is_string(old('time')) ? old('time') : '' }}" required @error('time') aria-invalid="true" @enderror>
        <p class="account-help">Duración: 60 minutos. Debe caber en un intervalo publicado y terminar el mismo día. Fechas hasta {{ implode('/', array_reverse(explode('-', $maxDate))) }}. Las horas inexistentes o ambiguas por cambios de horario se rechazan.</p>
        <button class="account-button" type="submit" @disabled($commercial && count($commercial['accepted_quotes']) === 0)>Enviar propuesta de horario</button>
      </form>
      <p><a href="{{ route('inquiries.show', $inquiry) }}">Volver a la solicitud y conversación</a></p>
    </section>
    <x-provider-availability :profile="$inquiry->service->providerProfile" />
  </div>
</x-account-layout>
