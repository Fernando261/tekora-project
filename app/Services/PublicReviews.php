<?php

namespace App\Services;

use App\Exceptions\ReviewFailure;
use App\Models\Service;
use App\Models\ServiceReview;
use Illuminate\Support\Facades\DB;

class PublicReviews
{
    public function forService(int $serviceId, int $page = 1): ?array
    {
        return $this->read($serviceId, null, $page);
    }

    public function forProvider(int $profileId, int $page = 1): ?array
    {
        return $this->read(null, $profileId, $page);
    }

    private function read(?int $serviceId, ?int $profileId, int $page): ?array
    {
        try {
            return DB::transaction(function () use ($serviceId, $profileId, $page) {
                $services = Service::publiclyVisible();
                $serviceId !== null ? $services->whereKey($serviceId) : $services->where('provider_profile_id', $profileId);
                if (! $services->exists()) return null;
                $query = ServiceReview::publiclyVisible();
                $serviceId !== null ? $query->where('service_reviews.service_id', $serviceId) : $query->where('service_reviews.provider_profile_id', $profileId);
                $stats = (clone $query)->selectRaw('COUNT(*) as review_count, AVG(rating) as review_average')->first();
                // Una sola instantánea para promedio, recuento y página, sin caché ni consultas por reseña.
                $reviews = $query->join('services as reviewed_service', 'reviewed_service.id', '=', 'service_reviews.service_id')
                    ->select(['service_reviews.rating', 'service_reviews.comment', 'service_reviews.created_at', 'service_reviews.service_id', 'reviewed_service.title as service_title'])
                    ->orderByDesc('service_reviews.id')->paginate(5, ['*'], 'reviews_page', $page)->fragment('resenas');
                if ($page > $reviews->lastPage()) throw new ReviewFailure('La página de reseñas no existe.', 404);
                $reviews->through(fn (ServiceReview $review) => ['rating' => $review->rating, 'comment' => $review->comment,
                    'month' => $review->publicMonth(), 'service_id' => $review->service_id, 'service_title' => $review->service_title]);

                return ['count' => (int) $stats->review_count, 'average' => $stats->review_count > 0 ? round((float) $stats->review_average, 1) : null, 'reviews' => $reviews];
            }, 3);
        } catch (\PDOException $exception) {
            throw new ReviewFailure('Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', 503);
        }
    }
}
