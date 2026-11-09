<x-access-layout title="Nueva contraseña" :identity="true">
  <x-tekora-auth-card>
    <p class="account-eyebrow">Seguridad de tu cuenta</p><h1>Nueva contraseña</h1><p>Elige tu nueva contraseña y confírmala para recuperar el acceso.</p>
    <form class="account-form" method="POST" action="{{ route('password.update') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <label for="reset-email">Correo electrónico</label><input id="reset-email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required autocomplete="email" @error('email') aria-invalid="true" @enderror>
      <label for="reset-password">Nueva contraseña</label><input id="reset-password" type="password" name="password" required autocomplete="new-password" @error('password') aria-invalid="true" aria-describedby="reset-password-error" @enderror>
      <label for="reset-confirmation">Confirmar contraseña</label><input id="reset-confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
      @error('password')<p id="reset-password-error" class="tk-identity-field-error" role="alert">{{ $message }}</p>@enderror
      <button class="account-button" type="submit">Restablecer contraseña</button>
    </form><p class="access-footer"><a href="{{ route('login') }}">Volver al login</a></p>
  </x-tekora-auth-card>
</x-access-layout>
