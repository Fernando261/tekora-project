@props(['service'])
<article class="account-card service-card mp-card" data-market-card data-market-tilt>
  <span class="mp-card-plane" data-market-plane aria-hidden="true"></span>
  <div class="mp-service-top"><span class="mp-service-symbol" aria-hidden="true"><x-tekora-icon name="briefcase" /></span><span class="mp-service-mode">{{ $service->modality->label() }}</span></div>
  <span class="mp-card-category">{{ $service->category->name }}</span>
  <h2><a href="{{ route('catalog.show', $service) }}">{{ $service->title }}</a></h2>
  <p class="service-excerpt">{{ \Illuminate\Support\Str::limit($service->description, 180) }}</p>
  <a class="service-provider" href="{{ route('public-providers.show', $service->providerProfile) }}"><x-marketplace-avatar :name="$service->providerProfile->display_name" /><span>{{ $service->providerProfile->display_name }}</span></a>
  <a class="mp-card-action" href="{{ route('catalog.show', $service) }}">Ver servicio<span class="sr-only">: {{ $service->title }}</span><x-tekora-icon name="arrow" /></a>
</article>
