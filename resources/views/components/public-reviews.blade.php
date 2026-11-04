@props(['reviews', 'forProvider' => false])
@if($reviews !== null)
<section class="account-card public-reviews" id="resenas" aria-labelledby="public-reviews-title">
  <h2 id="public-reviews-title">{{ $forProvider ? 'Reseñas de sus servicios publicados' : 'Reseñas del servicio' }}</h2>
  @if($reviews['count'] === 0)<p>Aún no hay reseñas verificadas</p>@else
    <div class="review-summary"><x-review-stars :rating="$reviews['average']" /><strong>{{ number_format($reviews['average'], 1, '.', '') }} / 5</strong><span>{{ $reviews['count'] }} {{ $reviews['count'] === 1 ? 'reseña verificada' : 'reseñas verificadas' }}</span></div>
    <ol class="public-review-list">@foreach($reviews['reviews'] as $review)<li>
      <div class="review-public-heading"><strong>Cliente verificado</strong><span class="review-badge">Servicio verificado</span></div>
      <p><x-review-stars :rating="$review['rating']" /> <span class="account-help">{{ $review['month'] }}</span></p>
      @if($forProvider)<p><a href="{{ route('catalog.show', $review['service_id']) }}">{{ $review['service_title'] }}</a></p>@endif
      @if($review['comment'] !== null)<p class="review-comment">{{ $review['comment'] }}</p>@else<p class="account-help">Sin comentario.</p>@endif
    </li>@endforeach</ol>
    <x-catalog-pagination :paginator="$reviews['reviews']" label="Páginas de reseñas" />
  @endif
  <p class="account-help">La etiqueta corresponde a una reserva completada. No acredita un pago ni una verificación profesional.</p>
</section>
@endif
