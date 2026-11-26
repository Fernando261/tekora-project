@props(['credential' => null, 'required' => false, 'signup' => false])
<fieldset class="trust-license">
  <legend>{{ $signup ? 'Cédula para quienes ofrecen servicios' : 'Cédula profesional' }}</legend>
  <label for="license_disposition">Disponibilidad de cédula</label>
  <select id="license_disposition" name="license_disposition" @required($required)>
    @if(!$credential)<option value="">Seleccionar</option>@endif
    <option value="available" @selected(old('license_disposition', $credential?->license_number ? 'available' : null) === 'available')>Tengo cédula profesional</option>
    <option value="none" @selected(old('license_disposition', $credential && !$credential->license_number ? 'none' : null) === 'none')>No cuento con cédula y ofrezco servicios que no la requieren</option>
  </select>
  <label for="license_number">Número de cédula profesional</label>
  <input id="license_number" name="license_number" inputmode="numeric" pattern="[0-9]{5,16}" maxlength="16" value="{{ $credential?->license_number }}" autocomplete="off">
  <label class="trust-choice"><input type="checkbox" name="public_license" value="1" @checked(old('public_license', $credential?->public_license))>
    <span>Autorizo mostrar el número de cédula en mi ficha y servicios públicos de Tekora tras la revisión. Puedo retirar esta autorización.</span>
  </label>
  <small>El número y los documentos se usan para revisión profesional según el <a href="{{ route('legal.show', 'privacidad') }}">aviso de privacidad</a>.</small>
  @foreach(['license_disposition', 'license_number', 'public_license'] as $field)
    @error($field)<p role="alert">{{ $message }}</p>@enderror
  @endforeach
</fieldset>
