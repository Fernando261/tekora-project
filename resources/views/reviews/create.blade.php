<x-account-layout title="Dejar reseña">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/reviews.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Servicio completado</span><h1>Dejar reseña</h1><p>{{ $reservation->service->title }}</p></header>
  <section class="account-card account-narrow">
    <h2>Comparte tu experiencia</h2>
    <p class="account-note">Tu calificación y comentario serán públicos como «Cliente verificado». Una vez publicados no podrás editarlos. Podrás retirar la reseña, conservando su registro privado, pero no reemplazarla ni volver a publicarla.</p>
    <form class="account-form review-form" method="POST" action="{{ route('reviews.store', $reservation) }}">
      @csrf
      <fieldset class="review-rating"><legend>Calificación de 1 a 5 estrellas</legend>
        @foreach(range(1, 5) as $rating)<label><input type="radio" name="rating" value="{{ $rating }}" @checked((string) (is_scalar(old('rating')) ? old('rating') : '') === (string) $rating) required> {{ $rating }} {{ $rating === 1 ? 'estrella' : 'estrellas' }}</label>@endforeach
      </fieldset>
      <label for="review-comment">Comentario (opcional)</label>
      <textarea id="review-comment" name="comment" maxlength="1000" rows="5" aria-describedby="review-comment-help" @error('comment') aria-invalid="true" @enderror>{{ is_string(old('comment')) ? old('comment') : '' }}</textarea>
      <p id="review-comment-help" class="account-help">Máximo 1000 caracteres, en texto plano. Evita incluir datos personales. «Servicio verificado» indica una reserva completada; no acredita un pago ni una verificación profesional.</p>
      <button class="account-button" type="submit">Publicar reseña</button>
    </form>
    <p><a href="{{ route('reservations.show', $reservation) }}">Volver a la reserva</a></p>
  </section>
</x-account-layout>
