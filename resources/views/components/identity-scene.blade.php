@props(['variant' => 'identity'])
{{-- Abstract geometry only: never pass user information or credentials to this component. --}}
<div class="tk-identity-scene tk-identity-scene-{{ $variant }}" data-identity-scene aria-hidden="true">
  <div class="tk-identity-object">
    <span class="tk-identity-orbit"></span>
    <div class="tk-identity-plane tk-identity-back"><span class="tk-identity-lines"></span></div>
    <div class="tk-identity-plane tk-identity-center"><x-tekora-icon :name="$variant === 'security' ? 'shield' : 'user'" /><i></i><i></i></div>
    <div class="tk-identity-plane tk-identity-front"><x-tekora-icon :name="$variant === 'security' ? 'shield' : ($variant === 'profile' ? 'user' : 'briefcase')" /></div>
    <span class="tk-identity-satellite"></span>
  </div>
</div>
