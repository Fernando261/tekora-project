@props(['reservation'])
@if($reservation->status === \App\Enums\ReservationStatus::Completed)
  @can('create', [\App\Models\ServiceReview::class, $reservation])
    @php($ownReview = $reservation->review()->first())
    @if($ownReview)<p><a href="{{ route('reviews.show', $ownReview) }}">Consultar mi reseña</a></p>@else<p><a class="account-button secondary" href="{{ route('reviews.create', $reservation) }}">Dejar reseña</a></p>@endif
  @endcan
@endif
