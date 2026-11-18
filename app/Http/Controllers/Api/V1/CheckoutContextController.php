<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommercialRequest;
use App\Http\Resources\Api\V1\CheckoutContextResource;
use App\Payments\CheckoutContext;
use App\Payments\PricingResponse;

final class CheckoutContextController extends Controller
{
    public function __invoke(CommercialRequest $request, int $reservation, CheckoutContext $context)
    {
        return PricingResponse::run($request, fn () => new CheckoutContextResource($context->forReservation($request->user('sanctum'), $reservation)));
    }
}
