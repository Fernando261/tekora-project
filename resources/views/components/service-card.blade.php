@props(['service'])
<article class="account-card service-card">
  <div class="catalog-tags"><span>{{ $service->category->name }}</span><span>{{ $service->modality->label() }}</span></div>
  <h2><a href="{{ route('catalog.show', $service) }}">{{ $service->title }}</a></h2>
  <p class="service-excerpt">{{ \Illuminate\Support\Str::limit($service->description, 180) }}</p>
  <a class="service-provider" href="{{ route('public-providers.show', $service->providerProfile) }}">{{ $service->providerProfile->display_name }}</a>
  <a class="account-button secondary" href="{{ route('catalog.show', $service) }}">Ver servicio<span class="sr-only">: {{ $service->title }}</span></a>
</article>
