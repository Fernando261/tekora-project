<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Payments\CheckoutContext;
use App\Payments\IntegratedCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IntegratedCheckoutController extends Controller
{
    public function show(Request $request, int $reservation, IntegratedCheckout $checkout, CheckoutContext $context): JsonResponse
    {
        $actor = $request->user('sanctum');
        $item = $checkout->clientReservation($actor, $reservation);
        abort_unless($request->query() === [] && $request->getContent() === '', 422);
        $projection = $context->forReservation($actor, $reservation);

        return response()->json(['data' => [
            'reservation_id' => $item->id, 'attempt' => $checkout->latest($item), 'synthetic_only' => true,
            'can_choose' => IntegratedCheckout::enabled() && $projection['confirmation_readiness']['state'] === 'simulated_checkout_required',
            'can_confirm' => $projection['confirmation_readiness']['state'] === 'ready',
            'server_now' => $projection['server_now'], 'expires_at' => $projection['hold']['expires_at'] ?? null,
        ]]);
    }

    public function submit(Request $request, int $reservation, IntegratedCheckout $checkout): JsonResponse
    {
        $actor = $request->user('sanctum');
        $checkout->clientReservation($actor, $reservation);
        abort_unless($request->isJson() && $request->query() === [], 422);

        return response()->json(['data' => $checkout->submit($actor, $reservation, $request->all())]);
    }
}
