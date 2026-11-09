<x-account-layout :operations="true" title="Perfil profesional">
  <x-ops-heading variant="studio"><span class="account-eyebrow">Presentación profesional</span><h1>{{ $profile->display_name }}</h1></x-ops-heading>
  <section class="account-card account-narrow"><h2>{{ $profile->headline ?: 'Sin titular profesional' }}</h2><p class="account-bio">{{ $profile->bio ?: 'Sin biografía todavía.' }}</p><p class="account-note">Perfil sin verificación profesional. Tu ficha pública de Tekora depende de los servicios publicados; los registros históricos conservan su procedencia.</p>
    @can('update', $profile)<a class="account-button" href="{{ route('account.provider') }}">Editar mi perfil</a>@endcan
  </section>
</x-account-layout>
