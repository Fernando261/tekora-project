@props(['href', 'icon', 'active' => false, 'tekora' => false])
<a class="menu-item {{ $active ? 'active' : '' }}" href="{{ $href }}" @if($active) aria-current="page" @endif>
  @if($tekora)<x-tekora-icon :name="$icon" /><span class="tk-nav-label">{{ $slot }}</span>@else{{ $slot }}@endif
  {{ $badge ?? '' }}
</a>
