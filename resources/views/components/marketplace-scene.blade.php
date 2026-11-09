@props(['variant' => 'discover', 'initial' => 'T'])
<div class="mp-scene mp-scene-{{ $variant }}" data-market-tilt data-market-scene aria-hidden="true">
  <div class="mp-scene-stage" data-market-plane>
    <span class="mp-orbit mp-orbit-one"></span><span class="mp-orbit mp-orbit-two"></span>
    <span class="mp-connection mp-connection-one"></span><span class="mp-connection mp-connection-two"></span>
    <div class="mp-object mp-object-back"><span class="mp-object-grid"></span></div>
    <div class="mp-object mp-object-middle"><x-tekora-icon :name="$variant === 'people' ? 'user' : 'briefcase'" /><span class="mp-object-line"></span><span class="mp-object-line short"></span></div>
    <div class="mp-object mp-object-front">@if($variant === 'identity')<span class="mp-monogram">{{ $initial }}</span>@else<x-tekora-icon :name="$variant === 'people' ? 'user' : 'search'" /><span class="mp-object-line"></span><span class="mp-object-line short"></span>@endif</div>
    <div class="mp-object mp-object-small"><x-tekora-icon :name="$variant === 'people' ? 'chat' : 'grid'" /></div>
    <span class="mp-node mp-node-one"></span><span class="mp-node mp-node-two"></span><span class="mp-node mp-node-three"></span>
  </div>
  <span class="mp-scene-label">{{ $variant === 'people' ? 'PERSONAS · IDEAS · CONEXIONES' : ($variant === 'identity' ? 'UNA IDENTIDAD. NUEVAS POSIBILIDADES.' : 'EXPLORA · CONECTA · CREA') }}</span>
</div>
