<?php

namespace App\Services;

use App\Exceptions\ReviewFailure;
use App\Models\Service;
use App\Models\ServiceReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
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
                $query = ServiceReview::publiclyVisible();
                $serviceId !== null ? $query->where('service_reviews.service_id', $serviceId) : $query->where('service_reviews.provider_profile_id', $profileId);
                $statsQuery = (clone $query)->selectRaw('COUNT(*) as review_count, AVG(rating) as review_average');
                $pageQuery = $query->join('services as reviewed_service', 'reviewed_service.id', '=', 'service_reviews.service_id')
                    ->select(['service_reviews.rating', 'service_reviews.comment', 'service_reviews.created_at', 'service_reviews.service_id', 'reviewed_service.title as service_title'])
                    ->orderByDesc('service_reviews.id');
                if (DB::connection()->getDriverName() === 'mysql') {
                    $snapshot = $this->mysqlPage($services, $statsQuery, $pageQuery, $page);
                    if ($snapshot === null) {
                        return null;
                    }
                    [$stats, $reviews] = $snapshot;
                } else {
                    if (! $services->exists()) {
                        return null;
                    }
                    $stats = $statsQuery->first();
                    $reviews = $pageQuery->paginate(5, ['*'], 'reviews_page', $page);
                }
                if ($page > $reviews->lastPage()) {
                    throw new ReviewFailure('La página de reseñas no existe.', 404);
                }
                $reviews->fragment('resenas')->through(fn (ServiceReview $review) => ['rating' => $review->rating, 'comment' => $review->comment,
                    'month' => $review->publicMonth(), 'service_id' => $review->service_id, 'service_title' => $review->service_title]);

                return ['count' => (int) $stats->review_count, 'average' => $stats->review_count > 0 ? round((float) $stats->review_average, 1) : null, 'reviews' => $reviews];
            }, 3);
        } catch (\PDOException $exception) {
            throw new ReviewFailure('Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', 503);
        }
    }

    private function mysqlPage(Builder $services, Builder $stats, Builder $pageQuery, int $page): ?array
    {
        // One statement gives both aggregates and rows the same snapshot under READ COMMITTED.
        // The aggregate anchors the response even when the requested page has no rows.
        $rows = ServiceReview::query()->fromSub($stats, 'review_stats')
            ->leftJoinSub($pageQuery->addSelect('service_reviews.id as review_id')->forPage($page, 5), 'review_page',
                fn (JoinClause $join) => $join->whereRaw('1 = 1'))
            ->whereExists($services->selectRaw('1')->toBase())
            ->orderByDesc('review_page.review_id')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return [$rows->first(), new LengthAwarePaginator($rows->whereNotNull('review_id')->values(), (int) $rows->first()->review_count,
            5, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'reviews_page'])];
    }
}
