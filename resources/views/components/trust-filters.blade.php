@props(['filters'])
<div><label for="municipality">Municipio</label>
  <select id="municipality" name="municipality">
    <option value="">Todos los municipios</option>
    @foreach(\App\Support\Municipalities::NAMES as $slug => $name)
      <option value="{{ $slug }}" @selected(($filters['municipality'] ?? '') === $slug)>{{ $name }}</option>
    @endforeach
  </select>
</div>
<label class="trust-choice"><input type="checkbox" name="verified" value="1" @checked($filters['verified'] ?? false)> Profesionales verificados</label>
