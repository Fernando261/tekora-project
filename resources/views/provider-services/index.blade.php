<x-account-layout title="Mis servicios">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Experiencia de prestador</span><h1>Mis servicios</h1><p>Publica lo que ofreces y administra tus servicios desde tu misma cuenta.</p></header>
  @if(!$profile)
    <section class="account-card"><h2>Completa primero tu perfil profesional</h2><p>Tu nombre público, titular y biografía te presentarán junto a los servicios que publiques.</p><a class="account-button" href="{{ route('account.provider') }}">Completar mi perfil</a></section>
  @else
    <div class="catalog-actions"><a class="account-button" href="{{ route('provider-services.create') }}">Crear servicio</a><a href="{{ route('catalog.index') }}">Explorar servicios</a></div>
    <p class="account-note">Al publicar un servicio se muestra tu ficha de prestador. Puedes pausar o retirar la publicación cuando lo necesites. Publicar no acredita una verificación profesional.</p>
    <div class="catalog-grid owner-service-grid">
    @forelse($services as $service)
      <article class="account-card service-card">
        <span class="service-status status-{{ $service->status->value }}">{{ $service->status->label() }}</span><h2>{{ $service->title }}</h2><p>{{ $service->category->name }} · {{ $service->modality->label() }}</p>
        @if(!$service->category->is_active)<p class="account-note">Categoría inactiva: este servicio no se muestra públicamente. Elige otra categoría al editarlo.</p>@endif
        <a class="account-button secondary" href="{{ route('provider-services.edit', $service) }}">Editar servicio<span class="sr-only">: {{ $service->title }}</span></a>
        @if($service->status === \App\Enums\ServiceStatus::Published && $service->category->is_active)<a href="{{ route('catalog.show', $service) }}">Ver publicación</a>@endif
        <form class="service-status-form" method="POST" action="{{ route('provider-services.status', $service) }}">
          @csrf @method('PATCH')
          @if($service->status === \App\Enums\ServiceStatus::Published)
            <button class="account-button secondary" name="status" value="paused" type="submit">Pausar publicación</button><button class="catalog-text-button" name="status" value="draft" type="submit">Retirar a borrador</button>
          @else
            <button class="account-button" name="status" value="published" type="submit">{{ $service->status === \App\Enums\ServiceStatus::Paused ? 'Volver a publicar' : 'Publicar servicio' }}</button>
          @endif
        </form>
      </article>
    @empty
      <section class="account-card catalog-empty"><h2>Todavía no has creado servicios</h2><p>Empieza con un borrador. Podrás revisarlo antes de publicarlo.</p></section>
    @endforelse
    </div>
    <x-catalog-pagination :paginator="$services" />
  @endif
</x-account-layout>
