<x-account-layout title="Actualizar nombre" :identity="true" :ambient="false">
  <x-identity-heading variant="profile"><span class="account-eyebrow">Mis datos personales</span><h1>Actualizar nombre</h1><p>Actualiza el nombre de tu misma cuenta. Tus accesos e historial se conservan.</p></x-identity-heading>
  <section class="account-card account-narrow"><h2>¿Cómo te llamas?</h2>
    <form class="account-form" action="{{ route('perfil.updateNombre') }}" method="POST">
      @csrf @method('PUT')
      <label for="personal-name">Nombre</label><input id="personal-name" name="name" type="text" value="{{ is_string(old('name', auth('web')->user()->name)) ? old('name', auth('web')->user()->name) : '' }}" maxlength="255" required autocomplete="name" aria-describedby="name-help" @error('name') aria-invalid="true" @enderror>
      <p id="name-help" class="account-help">Hasta 255 caracteres. Los cambios se guardan únicamente al enviar el formulario.</p>
      @error('name')<p class="tk-identity-field-error" role="alert">{{ $message }}</p>@enderror
      <button class="account-button" type="submit">Guardar cambios</button>
    </form><p><a href="{{ route('perfil') }}">Volver al perfil</a></p>
  </section>
</x-account-layout>
