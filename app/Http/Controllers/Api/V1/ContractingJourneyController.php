<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JourneyRequest;
use App\Http\Resources\Api\V1\ContractingJourneyResource;
use App\Services\ContractingJourney;

final class ContractingJourneyController extends Controller
{
    public function __invoke(JourneyRequest $request, int $inquiry, ContractingJourney $journey)
    {
        return \App\Payments\PricingResponse::run($request, fn () => new ContractingJourneyResource($journey->forInquiry($request->user('sanctum'), $inquiry)));
    }
}
