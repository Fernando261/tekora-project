@props(['reservation', 'journeyReturn' => false])
@if($reservation->status === \App\Enums\ReservationStatus::Completed)
  @can('create', [\App\Models\ServiceReview::class, $reservation])
    @php($ownReview = $reservation->review()->first())
    @if($ownReview)<p><a href="{{ route('reviews.show', $journeyReturn ? ['review' => $ownReview->id, 'follow' => 1] : $ownReview) }}">Consultar mi reseña</a></p>@else<p><a class="account-button secondary" href="{{ route('reviews.create', $journeyReturn ? ['reservation' => $reservation->id, 'follow' => 1] : $reservation) }}">Dejar reseña</a></p>@endif
  @endcan
@endif
