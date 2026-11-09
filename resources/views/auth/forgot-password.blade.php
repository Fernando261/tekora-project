<x-access-layout title="Recuperar contraseña" :identity="true">
  <x-tekora-auth-card>
    <p class="account-eyebrow">Recupera tu acceso</p><h1>Recuperar contraseña</h1><p>Solicita un enlace para restablecer el acceso a tu cuenta Tekora.</p>
    <form class="account-form" method="POST" action="{{ route('password.email') }}">
      @csrf
      <label for="recovery-email">Correo electrónico</label><input id="recovery-email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="recovery-email-error" @enderror>
      @error('email')<p id="recovery-email-error" class="tk-identity-field-error" role="alert">{{ $message }}</p>@enderror
      <button class="account-button" type="submit">Enviar enlace de recuperación</button>
    </form><p class="access-footer"><a href="{{ route('login') }}">Volver al login</a></p>
  </x-tekora-auth-card>
</x-access-layout>
