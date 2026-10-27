<x-account-layout title="Mi cuenta">
  <header class="account-heading"><span class="account-eyebrow">Una cuenta, varias posibilidades</span><h1>Hola, {{ $user->name }}</h1><p>Elige lo que quieres hacer. Puedes contratar y ofrecer servicios sin cerrar sesión ni crear otra cuenta.</p></header>
  <div class="account-grid">
    <section class="account-card"><h2>Quiero contratar</h2><p>Accede a tu experiencia de cliente con esta misma cuenta.</p>
      @can(\App\Enums\Permission::ViewClientAppointments->value)
        <a class="account-button" href="{{ route('account.client') }}">Ir a Cliente</a>
      @else
        <form action="{{ route('identity.roles.store') }}" method="POST">@csrf<input type="hidden" name="role" value="client"><button class="account-button" type="submit">Activar Cliente</button></form>
      @endcan
    </section>
    <section class="account-card"><h2>Quiero ofrecer servicios</h2><p>Crea tu presentación como técnico o freelancer. Tu acceso de cliente se conserva.</p>
      @can(\App\Enums\Permission::ManageProviderProfile->value)
        <a class="account-button" href="{{ route('account.provider') }}">Ir a Prestador</a>
      @else
        <a class="account-button" href="{{ route('account.activate-provider') }}">Convertirme en prestador</a>
      @endcan
    </section>
    @can(\App\Enums\Permission::ManageAccountRoles->value)
      <section class="account-card"><h2>Administración</h2><p>Consulta los roles disponibles para supervisar los accesos de la plataforma.</p><a class="account-button secondary" href="{{ route('identity.roles.index') }}">Consultar roles</a></section>
    @endcan
  </div>
</x-account-layout>
