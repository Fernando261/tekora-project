<x-access-layout title="Iniciar sesión">
  <x-tekora-auth-card>
    <span class="account-eyebrow">Cuenta única · Contrata y ofrece</span>
    <div class="tk-auth-symbol" aria-hidden="true"><span class="tk-mark"><i></i><i></i></span></div><h1>Iniciar sesión</h1>
    <p>Entra con tu cuenta para contratar servicios, ofrecerlos o hacer ambas cosas.</p>
    <form class="account-form" method="POST" action="{{ route('login.post') }}">
      @csrf
      <label for="email">Correo electrónico</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
      @error('email')<span id="email-error" class="tk-field-error">{{ $message }}</span>@enderror
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
      <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
      <button class="account-button" type="submit">Entrar a mi cuenta</button>
    </form>
    <p class="access-footer">¿No tienes cuenta? <a href="{{ route('registro.create') }}" data-auth-switch>Crear una cuenta única <x-tekora-arrow /></a></p>
    <p class="access-footer"><a href="{{ route('catalog.index') }}">Explorar servicios sin iniciar sesión</a></p>
  </x-tekora-auth-card>
</x-access-layout>
