<x-catalog-layout title="Explorar prestadores">
  <header class="account-heading catalog-heading"><span class="account-eyebrow">Talento técnico y profesional</span><h1>Explorar prestadores</h1><p>Conoce a quienes tienen servicios publicados en el catálogo.</p></header>
  <div class="catalog-grid">
    @forelse($providers as $profile)
      <article class="account-card provider-card"><h2><a href="{{ route('public-providers.show', $profile) }}">{{ $profile->display_name }}</a></h2><p class="provider-headline">{{ $profile->headline }}</p><p class="service-excerpt">{{ \Illuminate\Support\Str::limit($profile->bio ?? '', 180) }}</p><p>{{ $profile->published_services_count }} {{ $profile->published_services_count === 1 ? 'servicio publicado' : 'servicios publicados' }}</p><a class="account-button secondary" href="{{ route('public-providers.show', $profile) }}">Ver perfil y servicios</a></article>
    @empty
      <section class="account-card catalog-empty"><h2>Aún no hay prestadores con servicios publicados</h2><p>Vuelve pronto para conocer sus propuestas.</p></section>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$providers" />
</x-catalog-layout>
