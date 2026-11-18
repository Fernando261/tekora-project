<?php

namespace App\Http\Controllers;

use App\Http\Resources\Api\V1\CheckoutContextResource;
use App\Payments\CheckoutContext;
use App\Payments\PricingInput;
use App\Payments\PricingResponse;
use Illuminate\Http\Request;

final class CommercialContextController extends Controller
{
    public function __invoke(Request $request, int $reservation, CheckoutContext $context)
    {
        return PricingResponse::run($request, function () use ($request, $reservation, $context) {
            $snapshot = $context->forReservation($request->user('web'), $reservation);
            PricingInput::only($request->all());

            return new CheckoutContextResource($snapshot);
        });
    }
}
