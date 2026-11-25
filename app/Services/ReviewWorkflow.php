<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\ReviewVisibility;
use App\Exceptions\ReviewFailure;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use App\Support\ReviewInput;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PDOException;

class ReviewWorkflow
{
    use DetectsConcurrencyErrors;

    /** @return array{review: ServiceReview, created: bool} */
    public function publish(User $actor, int $reservationId, array $input): array
    {
        return $this->safely(function (bool $mysql) use ($actor, $reservationId, $input) {
            $operation = function (bool $existingOnly = false) use ($actor, $reservationId, $input, $mysql) {
                $reservations = ServiceReservation::query();
                if ($mysql) {
                    // Serialize publications even when there is no review row yet.
                    $reservations->lockForUpdate();
                }
                $reservation = $reservations->findOrFail($reservationId);
                Gate::forUser($actor)->authorize('create', [ServiceReview::class, $reservation]);
                if ($reservation->status !== ReservationStatus::Completed) {
                    throw new ReviewFailure('Solo puedes reseñar una reserva completada.');
                }
                $data = ReviewInput::publish($input);
                $reviews = ServiceReview::where('service_reservation_id', $reservationId);
                if ($mysql) {
                    // A consistent read can return the old published state after a withdrawal.
                    $reviews->lockForUpdate();
                }
                $existing = $reviews->first();
                if ($existing) {
                    return $this->replay($existing, $data);
                }
                if ($existingOnly) {
                    throw new ReviewFailure;
                }

                $review = new ServiceReview;
                $review->service_reservation_id = $reservation->id;
                $review->service_id = $reservation->service_id;
                $review->provider_profile_id = $reservation->provider_profile_id;
                $review->client_user_id = $reservation->client_user_id;
                $review->rating = $data['rating'];
                $review->comment = $data['comment'];
                $review->status = ReviewVisibility::Published;
                $review->created_at = now('UTC')->format('Y-m-d H:i:s');
                // El INSERT obtiene el bloqueo de escritura; UNIQUE y los triggers son la garantía final.
                // No se escribe en reservas ni eventos para obtener un bloqueo artificial.
                $review->save();
                Gate::forUser($actor)->authorize('create', [ServiceReview::class, $reservation->fresh()]);

                return ['review' => $review->fresh(), 'created' => true];
            };
            try {
                return DB::transaction(fn () => $operation(), 3);
            } catch (UniqueConstraintViolationException $exception) {
                // InnoDB serializes this lookup and insert via the reservation lock. A duplicate
                // here can come from an AFTER trigger, in which case the review was rolled back.
                if ($mysql || ($exception->errorInfo[2] ?? null) !== 'UNIQUE constraint failed: service_reviews.service_reservation_id') {
                    throw new ReviewFailure('Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', 503);
                }

                // Tras el rollback, volver a autorizar y reconocer únicamente el mismo contenido.
                return DB::transaction(fn () => $operation(true), 3);
            }
        });
    }

    public function withdraw(User $actor, int $reviewId, array $input = []): ServiceReview
    {
        return $this->safely(function (bool $mysql) use ($actor, $reviewId, $input) {
            return DB::transaction(function () use ($actor, $reviewId, $input, $mysql) {
                $reviews = ServiceReview::query();
                if ($mysql) {
                    $reviews->lockForUpdate();
                }
                $review = $reviews->findOrFail($reviewId);
                Gate::forUser($actor)->authorize('withdraw', $review);
                ReviewInput::only($input);
                if ($review->status === ReviewVisibility::Withdrawn) {
                    return $review;
                }
                ServiceReview::whereKey($reviewId)->where('status', ReviewVisibility::Published->value)->update([
                    'status' => ReviewVisibility::Withdrawn->value, 'withdrawn_at_utc' => now('UTC')->format('Y-m-d H:i:s'), 'withdrawn_by_user_id' => $actor->id]);
                $updated = $review->fresh();
                Gate::forUser($actor)->authorize('withdraw', $updated);

                return $updated;
            }, 3);
        });
    }

    private function replay(ServiceReview $review, array $data): array
    {
        if ($review->rating !== $data['rating'] || $review->comment !== $data['comment']) {
            throw new ReviewFailure('Esta reserva ya tiene una reseña. No se puede cambiar ni reemplazar su calificación o comentario.');
        }

        return ['review' => $review, 'created' => false];
    }

    private function safely(callable $operation): mixed
    {
        $driver = DB::connection()->getDriverName();
        try {
            return $operation($driver === 'mysql');
        } catch (PDOException $exception) {
            // Laravel can wrap a nested deadlock without copying its PDO errorInfo.
            $cause = $exception->getPrevious();
            [$state, $code, $diagnostic] = array_pad($exception->errorInfo ?? ($cause instanceof PDOException ? $cause->errorInfo : []) ?? [], 3, null);
            $conflict = match ($driver) {
                'sqlite' => in_array(((int) $code) & 255, [5, 6, 19], true) || $this->causedByConcurrencyError($exception),
                'mysql' => in_array((int) $code, [1205, 1213, 1451, 1452, 3819], true)
                    || ((int) $code === 1644 && $state === '45000' && in_array($diagnostic,
                        ['review_initial_state', 'review_completed_identity', 'review_immutable', 'review_history_required'], true)),
                default => false,
            };
            throw new ReviewFailure($conflict ? 'Hay un conflicto con el envío. Consulta Mis reseñas antes de reintentar.' : 'Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', $conflict ? 409 : 503);
        }
    }
}
