<x-catalog-layout :title="$service->title" :description="\Illuminate\Support\Str::limit($service->description, 155)">
  <a class="catalog-back" href="{{ route('catalog.index') }}"><span aria-hidden="true">←</span> Explorar servicios</a>
  <div class="mp-detail-layout">
    <article class="account-card catalog-detail mp-detail-main">
      <span class="mp-kicker" data-market-enter>UNA PROPUESTA. NUEVAS POSIBILIDADES.</span>
      <x-marketplace-tags :service="$service" />
      <h1 data-market-enter>{{ $service->title }}</h1>
      <div class="mp-detail-rule" aria-hidden="true"><span></span></div>
      <h2>Sobre este servicio</h2><p class="catalog-bio">{{ $service->description }}</p>
      <p class="account-note">Publicación sin verificación profesional.</p>
    </article>
    <aside class="account-card mp-provider-aside" aria-label="Prestador y solicitud">
      <span class="mp-kicker">LA PERSONA DETRÁS</span><x-marketplace-avatar :name="$service->providerProfile->display_name" />
      <h2>{{ $service->providerProfile->display_name }}</h2>
      @if($service->providerProfile->headline)<p class="provider-headline">{{ $service->providerProfile->headline }}</p>@endif
      <a class="mp-text-link" href="{{ route('public-providers.show', $service->providerProfile) }}">Conocer al prestador y sus servicios <x-tekora-icon name="arrow" /></a>
      <div class="catalog-actions">
        @guest('web')
          <a class="account-button" href="{{ route('inquiries.create', $service) }}">Solicitar servicio <x-tekora-icon name="arrow" /></a><p>Inicia sesión o crea tu cuenta para enviar una solicitud.</p>
        @else
          @can('create', [\App\Models\ServiceInquiry::class, $service])
            <a class="account-button" href="{{ route('inquiries.create', $service) }}">Solicitar servicio <x-tekora-icon name="arrow" /></a>
          @else
            @can(\App\Enums\Permission::ManageOwnInquiries->value)<p>Este es un servicio propio. No puedes solicitarlo desde tu cuenta.</p>@else<p>Activa Cliente desde <a href="{{ route('account.index') }}">Mi cuenta</a> para solicitar servicios.</p>@endcan
          @endcan
        @endguest
      </div>
      <p class="account-help">Enviar una solicitud no crea reservas, pagos ni contratos automáticos.</p>
    </aside>
  </div>
  <div class="mp-public-context"><x-provider-availability :profile="$service->providerProfile" /><x-public-reviews :reviews="$reviews" /></div>
</x-catalog-layout>
