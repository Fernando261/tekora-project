<x-tekora-layout>
  <section class="tk-hero tk-wrap" aria-labelledby="hero-heading">
    <div class="tk-hero-copy">
      <p class="tk-eyebrow" data-hero-enter><span class="tk-status-dot" aria-hidden="true"></span> TALENTO QUE CONECTA. IDEAS QUE AVANZAN.</p>
      <h1 id="hero-heading" data-hero-enter>Tu próxima idea.<br>El talento que<br><em>la hace posible.</em></h1>
      <p class="tk-hero-description" data-hero-enter>Encuentra un servicio, comparte lo que necesitas y conecta con quien puede ayudarte a hacerlo realidad.</p>
      <div class="tk-actions" data-hero-enter>
        <a class="tk-button" href="{{ route('catalog.index') }}">Explorar servicios <x-tekora-arrow /></a>
        <a class="tk-text-link" href="{{ route('registro.create', ['intent' => 'provider']) }}">Quiero ofrecer mi talento <span aria-hidden="true">→</span></a>
      </div>
      <p class="tk-hero-note" data-hero-enter><span aria-hidden="true"></span> UNA CUENTA PARA CONTRATAR Y OFRECER.</p>
    </div>
    <x-tekora-scene />
  </section>

  <section class="tk-explore tk-wrap" aria-labelledby="explore-heading" id="explorar">
    <div class="tk-section-heading" data-reveal>
      <p class="tk-eyebrow">01 / ENCUENTRA TU CONEXIÓN</p>
      <h2 id="explore-heading">¿Qué quieres <em>hacer posible?</em></h2>
      <p>Empieza por lo que necesitas. El siguiente paso es conversar.</p>
    </div>
    <form class="tk-search" method="GET" action="{{ route('catalog.index') }}" role="search" data-reveal>
      <label for="service-search">Buscar un servicio</label>
      <div><span class="tk-search-icon" aria-hidden="true">⌕</span><input id="service-search" type="search" name="q" maxlength="120" placeholder="¿En qué podemos ayudarte?" autocomplete="off"><button class="tk-button" type="submit">Buscar <x-tekora-arrow /></button></div>
    </form>
    <nav class="tk-categories" aria-label="Categorías activas" data-reveal>
      @forelse($categories as $category)
        <a href="{{ route('catalog.index', ['category' => $category->slug]) }}">{{ $category->name }} <x-tekora-arrow /></a>
      @empty
        <p class="tk-empty">Las categorías aparecerán aquí cuando estén disponibles. Puedes explorar el catálogo.</p>
      @endforelse
    </nav>
  </section>

  <section class="tk-section tk-wrap" aria-labelledby="services-heading">
    <div class="tk-section-heading tk-heading-row" data-reveal><div><p class="tk-eyebrow">02 / EXPLORA EL TALENTO</p><h2 id="services-heading">Servicios con<br><em>nuevas posibilidades.</em></h2></div><a class="tk-text-link" href="{{ route('catalog.index') }}">Ver catálogo <x-tekora-arrow /></a></div>
    <div class="tk-service-grid">
      @forelse($services as $service)
        <article class="tk-service-card" data-reveal>
          <div class="tk-card-top"><span class="tk-category-label">{{ $service->category->name }}</span><span class="tk-card-symbol" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} /</span></div>
          <span class="tk-service-art tk-service-art-{{ $loop->iteration }}" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="tk-modality">{{ $service->modality->label() }}</span>
          <h3><a href="{{ route('catalog.show', $service->id) }}">{{ $service->title }} <x-tekora-arrow /></a></h3>
          <p class="tk-service-description">{{ \Illuminate\Support\Str::limit($service->description, 135) }}</p>
          <p class="tk-provider-name">Por {{ $service->providerProfile->display_name }}</p>
        </article>
      @empty
        <div class="tk-empty tk-empty-wide" data-reveal><span class="tk-empty-symbol" aria-hidden="true">✧</span><h3>El próximo encuentro está por llegar.</h3><p>Cuando haya servicios publicados, podrás explorarlos aquí.</p><a class="tk-text-link" href="{{ route('registro.create', ['intent' => 'provider']) }}">Comparte lo que sabes hacer →</a></div>
      @endforelse
    </div>
  </section>

  <section class="tk-process tk-section tk-wrap" id="como-funciona" aria-labelledby="process-heading">
    <div class="tk-section-heading" data-reveal><p class="tk-eyebrow">03 / ASÍ COMIENZA</p><h2 id="process-heading">De una idea<br><em>a una conversación.</em></h2></div>
    <ol class="tk-steps">
      <li data-reveal><span>01</span><h3>Encuentra un servicio.</h3><p>Explora el catálogo y conoce lo que ofrece cada prestador.</p></li>
      <li data-reveal><span>02</span><h3>Cuéntale tu idea.</h3><p>Crea tu cuenta y envía una solicitud con lo que necesitas. Conversen sobre los detalles.</p></li>
      <li data-reveal><span>03</span><h3>Acuerden el siguiente paso.</h3><p>Si tu solicitud es aceptada, puedes proponer un horario. La reserva necesita confirmación del prestador.</p></li>
    </ol>
  </section>

  <section class="tk-section tk-wrap" aria-labelledby="providers-heading">
    <div class="tk-heading-row tk-section-heading" data-reveal><div><p class="tk-eyebrow">04 / DETRÁS DEL TALENTO</p><h2 id="providers-heading">Personas con<br><em>algo que aportar.</em></h2></div><a class="tk-text-link" href="{{ route('public-providers.index') }}">Conocer prestadores <x-tekora-arrow /></a></div>
    <div class="tk-providers">
      @forelse($providers as $provider)
        <a class="tk-person" href="{{ route('public-providers.show', $provider->id) }}" data-reveal><span class="tk-avatar" aria-hidden="true">{{ mb_substr($provider->display_name, 0, 1) }}</span><div><h3>{{ $provider->display_name }}</h3><p>{{ $provider->headline }}</p></div><x-tekora-arrow /></a>
      @empty
        <p class="tk-empty tk-empty-wide" data-reveal>Los perfiles con servicios públicos aparecerán aquí. Sin promesas, solo lo que cada persona ofrece.</p>
      @endforelse
    </div>
  </section>
  <section class="tk-end tk-wrap" aria-labelledby="end-heading" data-reveal><p class="tk-eyebrow">TU TALENTO TIENE UN LUGAR AQUÍ</p><h2 id="end-heading">Haz espacio para<br><em>lo que viene.</em></h2><p>Una sola cuenta para encontrar ayuda y compartir lo que sabes hacer.</p><a class="tk-button" href="{{ route('registro.create') }}">Crear mi cuenta <x-tekora-arrow /></a></section>
</x-tekora-layout>
