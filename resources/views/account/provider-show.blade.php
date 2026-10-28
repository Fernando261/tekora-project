<x-account-layout title="Perfil profesional">
  <header class="account-heading"><span class="account-eyebrow">Presentación profesional</span><h1>{{ $profile->display_name }}</h1></header>
  <section class="account-card account-narrow"><h2>{{ $profile->headline ?: 'Sin titular profesional' }}</h2><p class="account-bio">{{ $profile->bio ?: 'Sin biografía todavía.' }}</p><p class="account-note">Perfil sin verificación profesional. No forma parte del directorio anterior de MindBloom.</p>
    @can('update', $profile)<a class="account-button" href="{{ route('account.provider') }}">Editar mi perfil</a>@endcan
  </section>
</x-account-layout>
