<x-account-layout :identity="true" title="Mi cuenta">
  <x-identity-heading variant="identity"><span class="account-eyebrow">Una cuenta, varias posibilidades</span><h1>Hola, {{ $user->name }}</h1><p>Elige lo que quieres hacer. Puedes contratar y ofrecer servicios sin cerrar sesión ni crear otra cuenta.</p></x-identity-heading>
  <p class="tk-identity-context"><x-tekora-icon name="user" /> Tu identidad central · Accesos según tus permisos</p>
  <div class="account-grid">
    <section class="account-card tk-identity-experience" data-identity-card><h2>Quiero contratar</h2><p>Accede a tu experiencia de cliente con esta misma cuenta.</p>
      @can(\App\Enums\Permission::ViewClientAppointments->value)
        <span class="tk-identity-state">Experiencia disponible</span>
        <a class="account-button" href="{{ route('account.client') }}">Ir a Cliente</a>
      @else
        <span class="tk-identity-state is-pending">Aún no activada</span>
        <form action="{{ route('identity.roles.store') }}" method="POST">@csrf<input type="hidden" name="role" value="client"><button class="account-button" type="submit">Activar Cliente</button></form>
      @endcan
    </section>
    <section class="account-card tk-identity-experience" data-identity-card><h2>Quiero ofrecer servicios</h2><p>Crea tu presentación como técnico o freelancer. Tu acceso de cliente se conserva.</p>
      @can(\App\Enums\Permission::ManageProviderProfile->value)
        <span class="tk-identity-state">Experiencia disponible</span>
        <a class="account-button" href="{{ route('account.provider') }}">Ir a Prestador</a>
        <p><a href="{{ route('provider.dashboard') }}">Abrir panel del prestador →</a></p>
      @else
        <span class="tk-identity-state is-pending">Aún no activada</span>
        <a class="account-button" href="{{ route('account.activate-provider') }}">Convertirme en prestador</a>
      @endcan
    </section>
    @can(\App\Enums\Permission::ManageAccountRoles->value)
      <section class="account-card tk-identity-experience" data-identity-card><h2>Administración</h2><p>Consulta los roles disponibles para supervisar los accesos de la plataforma.</p><a class="account-button secondary" href="{{ route('identity.roles.index') }}">Consultar roles</a></section>
    @endcan
  </div>
  <section class="account-card tk-identity-personal-link"><div><h2>Tus datos, en un solo lugar</h2><p>Revisa tu nombre, correo y foto de perfil desde tu cuenta privada.</p></div><a class="account-button secondary" href="{{ route('perfil') }}">Mis datos personales</a></section>
</x-account-layout>
