<x-account-layout :identity="true" title="Roles disponibles">
  <x-identity-heading variant="security"><span class="account-eyebrow">Administración autorizada</span><h1>Roles disponibles</h1><p>Catálogo de accesos de la plataforma. Esta pantalla es de consulta.</p></x-identity-heading>
  <section class="account-card"><h2>Accesos definidos</h2><ul class="account-role-list tk-identity-roles">@forelse($roles as $role)<li><x-tekora-icon name="shield" /><strong>{{ $role->name }}</strong><span>Rol del catálogo · Solo consulta</span></li>@empty<li>No hay roles disponibles para consultar.</li>@endforelse</ul></section>
</x-account-layout>
