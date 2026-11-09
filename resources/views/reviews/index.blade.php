<x-account-layout :operations="true" title="Mis reseñas">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reviews.css') }}">@endpush
  <x-ops-heading variant="connect"><span class="account-eyebrow">Experiencia de cliente</span><h1>Mis reseñas</h1><p>Consulta tus valoraciones y su visibilidad. Cada reserva completada admite una sola reseña.</p></x-ops-heading>
  @if($reviews->isEmpty())<section class="account-card"><h2>Todavía no has dejado reseñas</h2><p>Puedes valorar un servicio desde tu reserva completada.</p><a href="{{ route('reservations.index') }}">Consultar Mis reservas</a></section>@else
    <div class="review-list">@foreach($reviews as $review)<article data-ops-card class="account-card review-card">
      <span class="review-badge {{ $review->status === \App\Enums\ReviewVisibility::Withdrawn ? 'is-withdrawn' : '' }}">{{ $review->status->label() }}</span>
      <h2>{{ $review->service->title }}</h2><x-review-stars :rating="$review->rating" />
      @if($review->comment !== null)<p class="review-comment">{{ $review->comment }}</p>@else<p class="account-help">Sin comentario.</p>@endif
      <p><a href="{{ route('reviews.show', $review) }}">Consultar mi reseña</a></p>
    </article>@endforeach</div>
    <x-catalog-pagination :paginator="$reviews" />
  @endif
</x-account-layout>
