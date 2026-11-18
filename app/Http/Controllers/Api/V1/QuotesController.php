<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommercialRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\QuoteAcceptanceResource;
use App\Http\Resources\Api\V1\QuoteResource;
use App\Payments\PricingResponse;
use App\Payments\QuoteReadModel;
use App\Payments\SyntheticQuotes;

final class QuotesController extends Controller
{
    public function index(CommercialRequest $request, int $inquiry, QuoteReadModel $read)
    {
        return PricingResponse::run($request, fn () => new ApiCollection($read->forInquiry($request->user('sanctum'), $inquiry,
            $request->integer('per_page', 12), $request->integer('page', 1))->through(fn ($snapshot) => new QuoteResource($snapshot))));
    }

    public function show(CommercialRequest $request, string $quote, QuoteReadModel $read)
    {
        return PricingResponse::run($request, fn () => new QuoteResource($read->detail($request->user('sanctum'), $quote)));
    }

    public function issue(CommercialRequest $request, int $inquiry, SyntheticQuotes $quotes, QuoteReadModel $read)
    {
        return PricingResponse::run($request, function () use ($request, $inquiry, $quotes, $read) {
            $quote = $quotes->issue($request->user('sanctum'), $inquiry, $request->validated());

            // Stable 200 for first issuance and replay; identity belongs to the domain.
            return new QuoteResource($read->detail($request->user('sanctum'), $quote->id));
        });
    }

    public function accept(CommercialRequest $request, string $quote, SyntheticQuotes $quotes, QuoteReadModel $read)
    {
        return PricingResponse::run($request, function () use ($request, $quote, $quotes, $read) {
            $quotes->accept($request->user('sanctum'), strtolower($quote));

            return new QuoteResource($read->detail($request->user('sanctum'), $quote));
        });
    }

    public function acceptance(CommercialRequest $request, string $quote, QuoteReadModel $read)
    {
        return PricingResponse::run($request, fn () => new QuoteAcceptanceResource($read->detail($request->user('sanctum'), $quote)));
    }
}
