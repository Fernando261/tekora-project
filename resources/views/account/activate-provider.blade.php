<x-account-layout :identity="true" title="Activar prestador">
  <x-identity-heading variant="identity"><span class="account-eyebrow">Ofrece lo que sabes hacer</span><h1>Conviértete en prestador</h1><p>Utiliza tu misma cuenta para presentarte como técnico o freelancer.</p></x-identity-heading>
  <section class="account-card account-narrow">
    <h2>Tu acceso de cliente se conserva</h2><p>Después de activar esta experiencia podrás completar tu nombre público, titular profesional y biografía.</p>
    <p class="account-note">La activación no es una verificación profesional. No crea una identidad histórica de MindBloom ni fusiona cuentas automáticamente. Tu ficha pública de Tekora depende de los servicios que publiques.</p>
    <ol class="tk-identity-steps"><li><strong>Activa esta experiencia</strong><span>Conserva la misma cuenta y sus accesos actuales.</span></li><li><strong>Completa tu presentación</strong><span>Nombre público, titular y biografía.</span></li><li><strong>Publica lo que ofreces</strong><span>La visibilidad depende de las reglas del catálogo.</span></li></ol>
    @can(\App\Enums\Permission::ManageProviderProfile->value)
      <a class="account-button" href="{{ route('account.provider') }}">Ya está activado: gestionar mi perfil</a>
    @else
      <form method="POST" action="{{ route('identity.roles.store') }}">@csrf<input type="hidden" name="role" value="provider"><button class="account-button" type="submit">Activar Prestador y completar mi perfil</button></form>
    @endcan
  </section>
</x-account-layout>
