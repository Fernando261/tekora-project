<x-catalog-layout :title="$service->title">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/availability.css') }}">@endpush
  <a class="catalog-back" href="{{ route('catalog.index') }}">← Explorar servicios</a>
  <article class="account-card catalog-detail">
    <div class="catalog-tags"><span>{{ $service->category->name }}</span><span>{{ $service->modality->label() }}</span></div>
    <h1>{{ $service->title }}</h1><p class="catalog-bio">{{ $service->description }}</p>
    <hr><h2>Sobre el prestador</h2><h3>{{ $service->providerProfile->display_name }}</h3>
    @if($service->providerProfile->headline)<p>{{ $service->providerProfile->headline }}</p>@endif
    <p>Publicación sin verificación profesional.</p>
    <a class="account-button secondary" href="{{ route('public-providers.show', $service->providerProfile) }}">Conocer al prestador y sus servicios</a>
    <div class="catalog-actions">
      @guest('web')
        <a class="account-button" href="{{ route('inquiries.create', $service) }}">Solicitar servicio</a><p>Inicia sesión o crea tu cuenta para enviar una solicitud.</p>
      @else
        @can('create', [\App\Models\ServiceInquiry::class, $service])
          <a class="account-button" href="{{ route('inquiries.create', $service) }}">Solicitar servicio</a>
        @else
          @can(\App\Enums\Permission::ManageOwnInquiries->value)<p>Este es un servicio propio. No puedes solicitarlo desde tu cuenta.</p>@else<p>Activa Cliente desde <a href="{{ route('account.index') }}">Mi cuenta</a> para solicitar servicios.</p>@endcan
        @endcan
      @endguest
    </div>
    <p class="account-help">Enviar una solicitud no crea reservas, pagos ni contratos automáticos.</p>
  </article>
  <x-provider-availability :profile="$service->providerProfile" />
</x-catalog-layout>
