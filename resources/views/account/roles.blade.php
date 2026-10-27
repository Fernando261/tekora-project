<x-account-layout title="Roles disponibles">
  <header class="account-heading"><span class="account-eyebrow">Administración autorizada</span><h1>Roles disponibles</h1><p>Catálogo de accesos de la plataforma. Esta pantalla es de consulta.</p></header>
  <section class="account-card"><ul class="account-role-list">@foreach($roles as $role)<li><strong>{{ $role->name }}</strong></li>@endforeach</ul></section>
</x-account-layout>
