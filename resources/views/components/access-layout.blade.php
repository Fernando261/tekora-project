@props(['title', 'identity' => false])
<x-tekora-layout :title="$title.' · Tekora'" page="access" :identity="$identity">
  <div class="tk-access-shell tk-wrap">
    <aside class="tk-access-story" aria-labelledby="access-story-title">
      <p class="tk-eyebrow">EL SIGUIENTE PASO EMPIEZA CONTIGO</p>
      <h2 id="access-story-title">Una cuenta.<br>Muchas formas<br><em>de conectar.</em></h2>
      <p>Encuentra servicios, conversa sobre tu proyecto o comparte tu propio talento.</p>
      @if($identity)<x-identity-scene variant="security" />@else<div class="tk-access-orb" aria-hidden="true"><span class="tk-core"><i></i><i></i><i></i></span></div>@endif
      <a class="tk-text-link" href="{{ route('catalog.index') }}">← Volver al catálogo</a>
    </aside>
    <div class="tk-access-content">
      <x-flash-messages />
      {{ $slot }}
    </div>
  </div>
</x-tekora-layout>
