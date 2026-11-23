<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\ReviewResource;
use App\Services\MobileClientOperations;
use App\Services\PublicReviews;
use App\Services\ReviewWorkflow;
use Illuminate\Http\JsonResponse;

final class ReviewsController extends Controller
{
    public function forService(ReviewRequest $request, PublicReviews $reviews): JsonResponse
    {
        return $this->publicResponse($reviews->forService($request->resourceId('service'), $request->integer('page', 1)));
    }

    public function forProvider(ReviewRequest $request, PublicReviews $reviews): JsonResponse
    {
        return $this->publicResponse($reviews->forProvider($request->resourceId('provider'), $request->integer('page', 1)));
    }

    public function index(ReviewRequest $request, MobileClientOperations $scope): ApiCollection
    {
        $reviews = $scope->reviews($request->user('sanctum'))->with('service:id,title')
            ->orderByDesc('id')->paginate(12, ['*'], 'page', $request->integer('page', 1));
        abort_if($reviews->currentPage() > $reviews->lastPage(), 404);

        return new ApiCollection($reviews->through(fn ($review) => new ReviewResource($review)));
    }

    public function show(ReviewRequest $request): ReviewResource
    {
        return new ReviewResource($request->attributes->get('mobile_review')->load('service:id,title'));
    }

    public function store(ReviewRequest $request, ReviewWorkflow $workflow): JsonResponse
    {
        $result = $workflow->publish($request->user('sanctum'), $request->resourceId('reservation'), $request->validated());

        return (new ReviewResource($result['review']->load('service:id,title')))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function withdraw(ReviewRequest $request, ReviewWorkflow $workflow): ReviewResource
    {
        $review = $workflow->withdraw($request->user('sanctum'), $request->resourceId('review'), $request->validated());

        return new ReviewResource($review->load('service:id,title'));
    }

    private function publicResponse(?array $summary): JsonResponse
    {
        abort_if($summary === null, 404);
        $page = $summary['reviews'];

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(),
            'total' => $summary['count'], 'average_rating' => $summary['average'],
        ]]);
    }
}
