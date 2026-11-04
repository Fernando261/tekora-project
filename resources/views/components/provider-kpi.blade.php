@props(['metric'])
<article class="account-card dashboard-kpi" data-kpi="{{ $metric['id'] }}">
  <span class="dashboard-scope {{ $metric['scope'] === 'period' ? 'is-period' : '' }}">{{ $metric['scope'] === 'period' ? 'Del periodo' : 'Estado actual' }}</span>
  <h2>{{ $metric['label'] }}</h2>
  <p class="dashboard-value">{{ $metric['value'] }}</p>
  @if($metric['note'])<p class="dashboard-note">{{ $metric['note'] }}</p>@endif
  <p class="dashboard-explanation">{{ $metric['description'] }}</p>
  @if($metric['url'])<a class="dashboard-kpi-link" href="{{ $metric['url'] }}">Consultar <span class="dashboard-sr-only">{{ $metric['label'] }}</span><span aria-hidden="true"> →</span></a>@endif
</article>
