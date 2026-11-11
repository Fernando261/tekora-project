<x-access-layout title="Crear cuenta">
  @php
    $selectedIntent = old('intent', request('intent', request('role') === 'prof' ? 'provider' : 'client'));
  @endphp
  <x-tekora-auth-card>
    <span class="account-eyebrow">Una cuenta para contratar y ofrecer</span>
    <div class="tk-auth-symbol" aria-hidden="true"><span class="tk-mark"><i></i><i></i></span></div><h1>Crear cuenta</h1>
    <p>No necesitas registrarte dos veces. Siempre podrás usar tu acceso de cliente y activar tu perfil de prestador.</p>
    <form class="account-form" method="POST" action="{{ route('registro.store') }}">
      @csrf
      <fieldset class="intent-options"><legend>¿Qué quieres hacer?</legend>
        @foreach(['client' => 'Contratar servicios', 'provider' => 'Ofrecer servicios', 'both' => 'Contratar y ofrecer'] as $value => $label)
          <label class="intent-option"><input type="radio" name="intent" value="{{ $value }}" {{ $selectedIntent === $value ? 'checked' : '' }} required><span>{{ $label }}</span></label>
        @endforeach
      </fieldset>
      <p class="account-help">Si eliges ofrecer, activaremos tu experiencia de prestador y conservaremos la de cliente. Después podrás completar tu perfil.</p>
      <label for="name">Nombre de tu cuenta</label>
      <input id="name" name="name" value="{{ old('name') }}" maxlength="255" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
      @error('name')<span id="name-error" class="tk-field-error">{{ $message }}</span>@enderror
      <div class="account-field-pair">
        <div><label for="age">Edad</label><input id="age" name="age" type="number" value="{{ old('age') }}" min="1" required @error('age') aria-invalid="true" aria-describedby="age-error" @enderror>@error('age')<span id="age-error" class="tk-field-error">{{ $message }}</span>@enderror</div>
        <div><label for="gender">Género</label><select id="gender" name="gender" required @error('gender') aria-invalid="true" aria-describedby="gender-error" @enderror>
          <option value="">Selecciona una opción</option>
          @foreach(['Hombre', 'Mujer', 'Otra identidad de género', 'Prefiero no decirlo'] as $gender)
            <option value="{{ $gender }}" {{ old('gender') === $gender ? 'selected' : '' }}>{{ $gender }}</option>
          @endforeach
        </select>@error('gender')<span id="gender-error" class="tk-field-error">{{ $message }}</span>@enderror</div>
      </div>
      <label for="email">Correo electrónico</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
      @error('email')<span id="email-error" class="tk-field-error">{{ $message }}</span>@enderror
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" minlength="6" required autocomplete="new-password" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
      @error('password')<span id="password-error" class="tk-field-error">{{ $message }}</span>@enderror
      <label for="password_confirmation">Confirmar contraseña</label>
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="6" required autocomplete="new-password" @error('password_confirmation') aria-invalid="true" aria-describedby="password_confirmation-error" @enderror>
      @error('password_confirmation')<span id="password_confirmation-error" class="tk-field-error">{{ $message }}</span>@enderror
      <button class="account-button" type="submit">Crear mi cuenta única</button>
    </form>
    <p class="access-footer">¿Ya tienes cuenta? <a href="{{ route('login') }}" data-auth-switch>Iniciar sesión</a></p>
  </x-tekora-auth-card>
</x-access-layout>
