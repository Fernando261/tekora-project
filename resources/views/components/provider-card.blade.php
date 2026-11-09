@props(['profile'])
<article class="account-card provider-card mp-card" data-market-card data-market-tilt>
  <span class="mp-card-plane" data-market-plane aria-hidden="true"></span>
  <div class="mp-provider-heading"><x-marketplace-avatar :name="$profile->display_name" /><span class="mp-kicker">PERFIL PÚBLICO</span></div>
  <h2><a href="{{ route('public-providers.show', $profile) }}">{{ $profile->display_name }}</a></h2>
  @if($profile->headline)<p class="provider-headline">{{ $profile->headline }}</p>@endif
  @if($profile->bio)<p class="service-excerpt">{{ \Illuminate\Support\Str::limit($profile->bio, 180) }}</p>@endif
  <p class="mp-published-count"><span aria-hidden="true"></span>{{ $profile->published_services_count }} {{ $profile->published_services_count === 1 ? 'servicio publicado' : 'servicios publicados' }}</p>
  <a class="account-button secondary" href="{{ route('public-providers.show', $profile) }}">Ver perfil y servicios <x-tekora-icon name="arrow" /></a>
</article>
