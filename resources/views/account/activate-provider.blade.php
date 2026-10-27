<x-account-layout title="Activar prestador">
  <header class="account-heading"><span class="account-eyebrow">Ofrece lo que sabes hacer</span><h1>Conviértete en prestador</h1><p>Utiliza tu misma cuenta para presentarte como técnico o freelancer.</p></header>
  <section class="account-card account-narrow">
    <h2>Tu acceso de cliente se conserva</h2><p>Después de activar esta experiencia podrás completar tu nombre público, titular profesional y biografía.</p>
    <p class="account-note">La activación no es una verificación profesional. Este perfil todavía no se publica en el directorio de MindBloom ni recibe sus citas.</p>
    @can(\App\Enums\Permission::ManageProviderProfile->value)
      <a class="account-button" href="{{ route('account.provider') }}">Ya está activado: gestionar mi perfil</a>
    @else
      <form method="POST" action="{{ route('identity.roles.store') }}">@csrf<input type="hidden" name="role" value="provider"><button class="account-button" type="submit">Activar Prestador y completar mi perfil</button></form>
    @endcan
  </section>
</x-account-layout>
