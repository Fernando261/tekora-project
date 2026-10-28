<x-account-layout title="Mi perfil profesional">
  <header class="account-heading"><span class="account-eyebrow">Experiencia de prestador</span><h1>Mi perfil profesional</h1><p>Presenta tu experiencia como técnico o freelancer usando tu misma cuenta.</p></header>
  <p class="account-alert">Activación sin verificación profesional. Este perfil no aparece todavía en el directorio de MindBloom ni recibe sus citas.</p>
  <section class="account-card"><h2>Solicitudes de tus servicios</h2><p>Revisa las necesidades de tus clientes y consulta su seguimiento.</p><a class="account-button" href="{{ route('inquiries.received') }}">Solicitudes recibidas</a></section>
  <section class="account-card"><h2>Los servicios que ofreces</h2><p>Al publicar un servicio en el catálogo Tekora, tu nombre público, titular y biografía aparecen en tu ficha pública de prestador.</p><a class="account-button secondary" href="{{ route('provider-services.index') }}">Mis servicios</a> @can('create', \App\Models\Service::class)<a class="account-button" href="{{ route('provider-services.create') }}">Crear servicio</a>@endcan</section>
  <div class="account-grid profile-grid">
    <section class="account-card">
      <h2>{{ $profile ? 'Editar mi presentación' : 'Completar mi presentación' }}</h2>
      <form class="account-form" method="POST" action="{{ $profile ? route('provider-profiles.update', $profile) : route('provider-profiles.store') }}">
        @csrf
        @if($profile) @method('PUT') @endif
        <label for="display_name">Nombre público</label>
        <input id="display_name" name="display_name" value="{{ old('display_name', $profile?->display_name ?? $user->name) }}" maxlength="255" required autocomplete="name" @error('display_name') aria-invalid="true" @enderror>
        <label for="headline">Titular profesional o especialidad descriptiva</label>
        <input id="headline" name="headline" value="{{ old('headline', $profile?->headline) }}" maxlength="160" placeholder="Por ejemplo: soporte informático y reparación de equipos">
        <label for="bio">Biografía</label>
        <textarea id="bio" name="bio" rows="7" maxlength="5000" placeholder="Cuenta tu experiencia y cómo puedes ayudar.">{{ old('bio', $profile?->bio) }}</textarea>
        <p class="account-help">La biografía admite hasta 5000 caracteres. Evita publicar contraseñas o información privada.</p>
        <button class="account-button" type="submit">{{ $profile ? 'Guardar cambios' : 'Crear mi perfil' }}</button>
      </form>
    </section>
    <section class="account-card profile-summary"><h2>Mi información actual</h2>
      @if($profile)
        <h3>{{ $profile->display_name }}</h3><p>{{ $profile->headline ?: 'Sin titular profesional todavía.' }}</p><p class="account-bio">{{ $profile->bio ?: 'Sin biografía todavía.' }}</p>
        <a class="account-button secondary" href="{{ route('provider-profiles.show', $profile) }}">Consultar mi perfil</a>
      @else
        <p>Aún no has guardado tu perfil. Completa tu presentación para consultarla aquí.</p>
      @endif
    </section>
  </div>
</x-account-layout>
