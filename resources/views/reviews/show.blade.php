<x-account-layout :operations="true" title="Mi reseña">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reviews.css') }}">@endpush
  <x-ops-heading variant="connect"><span class="account-eyebrow">Registro privado del autor</span><h1>Mi reseña</h1><p>{{ $review->service->title }}</p></x-ops-heading>
  <section class="account-card account-narrow">
    <span class="review-badge {{ $review->status === \App\Enums\ReviewVisibility::Withdrawn ? 'is-withdrawn' : '' }}">{{ $review->status->label() }}</span>
    <p><x-review-stars :rating="$review->rating" /> <strong>{{ $review->rating }} / 5</strong></p>
    @if($review->comment !== null)<p class="review-comment">{{ $review->comment }}</p>@else<p>Sin comentario.</p>@endif
    <p class="account-help">Creada: {{ $review->created_at }} UTC. Servicio verificado por una reserva completada; no acredita un pago.</p>
    @if($review->status === \App\Enums\ReviewVisibility::Withdrawn)
      <p class="account-note">Retirada de la presentación pública. Ya no cuenta en promedios ni recuentos. Solo tú puedes consultar este registro privado; no puedes crear otra reseña para esta reserva.</p>
      <p class="account-help">Retirada: {{ $review->withdrawn_at_utc }} UTC.</p>
    @else
      <p class="account-note">La reseña solo se muestra públicamente mientras el servicio cumpla las reglas del catálogo. No puedes editarla. Retirarla es irreversible y no permite reemplazarla.</p>
      @can('withdraw', $review)<form method="POST" action="{{ route('reviews.withdraw', $review) }}">@csrf @method('PATCH')<button class="account-button secondary tk-ops-danger" type="submit">Retirar reseña de la vista pública</button></form>@endcan
    @endif
    <p><a href="{{ route('reservations.show', $review->service_reservation_id) }}">Consultar mi reserva</a></p>
    <p><a href="{{ route('reviews.index') }}">Volver a Mis reseñas</a></p>
  </section>
</x-account-layout>
