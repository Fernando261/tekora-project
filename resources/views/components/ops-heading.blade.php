@props(['variant' => 'connect'])
<header class="account-heading tk-ops-heading">
  <div class="tk-ops-heading-copy">{{ $slot }}</div>
  <x-ops-scene :variant="$variant" />
</header>
