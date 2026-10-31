<x-account-layout title="Mi disponibilidad">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/availability.css') }}"><script src="{{ asset('js/availability.js') }}" defer></script>@endpush
  <header class="account-heading"><span class="account-eyebrow">Experiencia de prestador</span><h1>Mi disponibilidad</h1><p>Publica horarios semanales y fechas sin atención. No se crean reservas ni citas.</p></header>
  <p class="account-note">Horarios orientativos, sujetos a confirmación con el prestador</p>
  @if(!$setting)<p class="account-alert">Horario no publicado. La zona inicial propuesta es America/Mexico_City; se guardará al publicar tu configuración.</p>@endif
  @php
    $rows = old('intervals', $intervals); $rows = is_array($rows) ? $rows : [];
    $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && is_scalar($row['day_of_week'] ?? null) && in_array((string) $row['day_of_week'], ['1','2','3','4','5','6','7'], true)));
    $dates = old('blocked_dates', $blockedDates); $dates = is_array($dates) ? implode("\n", array_filter($dates, 'is_string')) : (is_string($dates) ? $dates : '');
    $zone = old('timezone', $setting?->timezone ?? \App\Models\ProviderAvailabilitySetting::DEFAULT_TIMEZONE); $zone = is_string($zone) ? $zone : '';
    $revision = old('revision', $setting?->revision ?? 0); $revision = is_scalar($revision) ? $revision : ($setting?->revision ?? 0);
    $rowIndex = 0;
    $blankBudget = max(0, 42 - count($rows));
  @endphp
  <form method="POST" action="{{ route('availability.update') }}" class="account-form availability-editor" data-availability-editor>
    @csrf @method('PATCH')
    <input type="hidden" name="revision" value="{{ $revision }}">
    <section class="account-card"><h2>Zona horaria</h2><label for="availability-timezone">Zona IANA de tus horarios</label><select id="availability-timezone" name="timezone" required>@foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected($zone === $timezone)>{{ $timezone }}</option>@endforeach</select><p class="account-help">Todas las horas son locales de esta zona. Si la cambias, se conservan los mismos números de hora y las mismas fechas.</p></section>
    <section class="account-card"><h2>Horario semanal</h2><p class="account-help">Deja ambas horas vacías para indicar sin atención. Completa inicio y fin de cada turno, sin cruzar medianoche. Los turnos pueden tocarse, pero no superponerse. Hasta 42 intervalos en total.</p>
      <div class="availability-days">
        @foreach(\App\Enums\AvailabilityDay::cases() as $day)
          @php($dayRows = array_values(array_filter($rows, fn ($row) => (int) $row['day_of_week'] === $day->value)))
          @php($extraRows = min($blankBudget, max(0, 2 - count($dayRows))))
          @php($blankBudget -= $extraRows)
          @php($dayRows = array_pad($dayRows, count($dayRows) + $extraRows, ['start' => '', 'end' => '']))
          <fieldset class="availability-day" data-day="{{ $day->value }}"><legend>{{ $day->label() }}</legend><div data-interval-rows>
            @foreach($dayRows as $row)
              <div class="availability-row" data-interval-row>
                <input type="hidden" name="intervals[{{ $rowIndex }}][day_of_week]" value="{{ $day->value }}">
                <label>Inicio<input type="time" name="intervals[{{ $rowIndex }}][start]" value="{{ is_string($row['start'] ?? null) ? $row['start'] : '' }}" step="60" aria-label="Inicio del turno de {{ $day->label() }}"></label>
                <label>Fin<input type="time" name="intervals[{{ $rowIndex }}][end]" value="{{ is_string($row['end'] ?? null) ? $row['end'] : '' }}" step="60" aria-label="Fin del turno de {{ $day->label() }}"></label>
                <button type="button" class="availability-remove" data-remove-interval hidden>Quitar turno<span class="availability-sr-only"> de {{ $day->label() }}</span></button>
              </div>
              @php($rowIndex++)
            @endforeach
          </div><button class="account-button secondary" type="button" data-add-interval hidden>Añadir turno en {{ $day->label() }}</button></fieldset>
        @endforeach
      </div>
      <p class="account-help" data-row-limit role="status"></p>
    </section>
    <section class="account-card"><h2>Fechas sin atención</h2><label for="blocked-dates">Una fecha por línea, AAAA-MM-DD</label><textarea id="blocked-dates" name="blocked_dates" rows="6" maxlength="1200" placeholder="2026-12-24&#10;2026-12-25">{{ $dates }}</textarea><p class="account-help">Hasta 100 fechas distintas entre 2000-01-01 y 2100-12-31. Se refieren al día completo de tu zona. Las pasadas se conservan aquí; la ficha pública solo muestra las vigentes. No se publican motivos personales.</p></section>
    <button class="account-button" type="submit">Guardar disponibilidad</button>
  </form>
  <template id="availability-interval-template"><div class="availability-row" data-interval-row><input type="hidden" data-field="day_of_week"><label>Inicio<input type="time" data-field="start" step="60"></label><label>Fin<input type="time" data-field="end" step="60"></label><button type="button" class="availability-remove" data-remove-interval>Quitar turno</button></div></template>
</x-account-layout>
