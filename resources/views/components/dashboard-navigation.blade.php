@props(['kind', 'sections'])
<nav class="tk-dashboard-navigation" aria-label="Secciones del panel de {{ $kind === 'provider' ? 'prestador' : 'cliente' }}" data-dashboard-navigation>
  <div class="tk-dashboard-track" data-dashboard-track>
    <span class="tk-dashboard-pill" data-dashboard-pill aria-hidden="true"></span>
    @foreach($sections as [$key, $label, $icon])
      <a id="{{ $kind }}-tab-{{ $key }}" href="#{{ $kind }}-{{ $key }}" data-dashboard-tab="{{ $key }}" @if($loop->first) aria-current="location" @endif><x-tekora-icon :name="$icon" /><span>{{ $label }}</span></a>
    @endforeach
  </div>
</nav>
