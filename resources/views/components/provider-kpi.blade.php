@props(['metric'])
@php($icons = ['published' => 'briefcase', 'received' => 'chat', 'pending' => 'clock', 'acceptance' => 'check', 'requested' => 'calendar', 'upcoming' => 'calendar', 'completed' => 'check', 'cancellation' => 'clock', 'rating' => 'star', 'unread' => 'chat'])
<article class="account-card dashboard-kpi" data-kpi="{{ $metric['id'] }}" data-accent="{{ ['published' => 'cyan', 'received' => 'mint', 'pending' => 'amber', 'acceptance' => 'mint', 'requested' => 'amber', 'upcoming' => 'mint', 'completed' => 'cyan', 'cancellation' => 'coral', 'rating' => 'violet', 'unread' => 'coral'][$metric['id']] }}" data-dashboard-card>
  <div class="tk-kpi-top">
  <span class="dashboard-scope {{ $metric['scope'] === 'period' ? 'is-period' : '' }}">{{ $metric['scope'] === 'period' ? 'Del periodo' : 'Estado actual' }}</span>
  <x-tekora-icon :name="$icons[$metric['id']] ?? 'grid'" /></div>
  <h2>{{ $metric['label'] }}</h2>
  <p class="dashboard-value {{ mb_strlen((string) $metric['value']) > 14 ? 'tk-value-long' : '' }}">{{ $metric['value'] }}</p>
  @if($metric['note'])<p class="dashboard-note">{{ $metric['note'] }}</p>@endif
  <p class="dashboard-explanation">{{ $metric['description'] }}</p>
  @if($metric['url'])<a class="dashboard-kpi-link" href="{{ $metric['url'] }}">Consultar <span class="dashboard-sr-only">{{ $metric['label'] }}</span><x-tekora-icon name="arrow" /></a>@endif
</article>
