@props(['variant' => 'connect'])
<div class="tk-ops-scene tk-ops-scene-{{ $variant }}" data-ops-scene aria-hidden="true">
  <div class="tk-ops-object">
    <span class="tk-ops-orbit"></span>
    <div class="tk-ops-plane tk-ops-plane-back"><span class="tk-ops-grid"></span></div>
    <div class="tk-ops-plane tk-ops-plane-middle"><x-tekora-icon :name="$variant === 'time' ? 'calendar' : ($variant === 'studio' ? 'briefcase' : 'chat')" /><i></i><i></i><i></i></div>
    <div class="tk-ops-plane tk-ops-plane-front"><x-tekora-icon :name="$variant === 'time' ? 'clock' : ($variant === 'studio' ? 'grid' : 'user')" /></div>
    <span class="tk-ops-node"></span>
  </div>
</div>
