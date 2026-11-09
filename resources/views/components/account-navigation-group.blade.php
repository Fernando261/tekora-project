@props(['label', 'enabled' => false])
@if(trim((string) $slot) !== '')
  @if($enabled)<div class="tk-nav-group" role="group" aria-label="{{ $label }}"><p class="tk-nav-heading" aria-hidden="true">{{ $label }}</p>@endif
  {{ $slot }}
  @if($enabled)</div>@endif
@endif
