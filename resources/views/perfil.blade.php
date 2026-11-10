<x-account-layout title="Mis datos personales" :identity="true" :ambient="false">
  @php($personalUser = auth('web')->user())
  <x-identity-heading variant="profile"><span class="account-eyebrow">Tu espacio privado</span><h1>Mis datos personales</h1><p>Tu identidad central. Revisa tu información y mantén tu presentación al día.</p></x-identity-heading>
  <div class="tk-identity-profile-grid">
    <section class="account-card tk-identity-profile-card" aria-labelledby="personal-data-title">
      <div class="tk-identity-profile-header"><x-identity-avatar :user="$personalUser" /><div><p class="account-eyebrow">Datos de tu cuenta</p><h2 id="personal-data-title">{{ $personalUser->name }}</h2><p>Esta información se muestra en tu espacio privado.</p></div></div>
      <dl class="tk-identity-data"><div><dt>Nombre</dt><dd>{{ $personalUser->name }}</dd></div><div><dt>Correo electrónico</dt><dd>{{ $personalUser->email }}</dd></div><div><dt>Edad</dt><dd>{{ $personalUser->age }}</dd></div><div><dt>Género</dt><dd>{{ $personalUser->gender }}</dd></div></dl>
      <a class="account-button" href="{{ route('perfil.editNombre') }}">Editar nombre</a>
    </section>
    <section class="account-card" aria-labelledby="photo-title"><h2 id="photo-title">Tu foto de perfil</h2><p>Elige una imagen y confirma su envío. La selección por sí sola no guarda cambios.</p>
      <form class="account-form" action="{{ route('perfil.updatePhoto') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="personal-photo">Selecciona una foto</label><input id="personal-photo" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required aria-describedby="photo-help" @error('photo') aria-invalid="true" @enderror>
        <p id="photo-help" class="account-help">JPG, JPEG, PNG o WebP. Máximo 2048 KB. El cambio se confirma después de la respuesta del servidor.</p>
        @error('photo')<p class="tk-identity-field-error" role="alert">{{ $message }}</p>@enderror
        <button class="account-button" type="submit">Guardar foto de perfil</button>
      </form>
    </section>
  </div>
  <section class="account-card tk-identity-personal-link"><div><h2>Una cuenta, tus experiencias</h2><p>Consulta los accesos disponibles según tus permisos actuales.</p></div><a class="account-button secondary" href="{{ route('account.index') }}">Ir a Mi cuenta</a></section>
  <details class="account-card tk-identity-history"><summary>Historial anterior · MindBloom</summary><p>Estos accesos mantienen su origen y funcionamiento. No se han migrado ni fusionado tus registros.</p><nav aria-label="Accesos históricos del perfil"><a href="{{ route('user.citas') }}">Citas anteriores</a><a href="{{ route('comunidad') }}">Comunidad anterior</a><a href="/conferencias">Conferencias anteriores</a></nav></details>
</x-account-layout>
