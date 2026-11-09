@props(['title'])
<section class="account-card catalog-empty">
  <span class="mp-empty-mark" aria-hidden="true"><x-tekora-icon name="search" /></span>
  <div><span class="mp-kicker">UN NUEVO PUNTO DE PARTIDA</span><h2>{{ $title }}</h2>{{ $slot }}</div>
</section>
