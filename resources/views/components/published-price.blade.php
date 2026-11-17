@props(['cents' => null])
@once @push('styles')<link rel="stylesheet" href="{{ asset('css/pricing.css') }}">@endpush @endonce
<p class="published-price {{ $cents === null ? 'price-unpublished' : '' }}">
  @if($cents !== null)<strong>{{ \App\Payments\WebMoney::format((int) $cents) }}</strong><span>/ 60 min</span>@else<span>Precio no publicado</span>@endif
</p>
