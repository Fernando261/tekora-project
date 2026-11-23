<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Payments\CheckoutContext;
use App\Payments\LocalCheckoutAttemptStore;
use App\Services\MobileClientOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer transport for the EXISTING B3.2 synthetic-only store.
 * No real card/bank transaction; no persistent payment migration.
 */
final class SyntheticCheckoutController extends Controller
{
    private function context(Request $request, int $reservation, MobileClientOperations $scope, CheckoutContext $checkout): array
    {
        abort_unless(LocalIntegratedCheckoutController::permitsSyntheticRequest($request)
            && Schema::hasTable('synthetic_checkout_attempts'), 404);
        $actor = $request->user('sanctum');
        // Ownership must be checked before parsing the submitted details.
        $scope->reservation($actor, $reservation);

        return $checkout->forReservation($actor, $reservation);
    }

    public function show(Request $request, int $reservation, MobileClientOperations $scope, CheckoutContext $checkout, LocalCheckoutAttemptStore $store): JsonResponse
    {
        $context = $this->context($request, $reservation, $scope, $checkout);
        abort_unless($request->all() === [] && $request->getContent() === '', 422);

        return $this->respond($context, $store->current($reservation));
    }

    public function submit(Request $request, int $reservation, MobileClientOperations $scope, CheckoutContext $checkout, LocalCheckoutAttemptStore $store): JsonResponse
    {
        $context = $this->context($request, $reservation, $scope, $checkout);
        abort_unless($context['future_payment']['commercial_eligible']
            && ($context['hold']['remaining_seconds'] ?? 0) > 0, 409);
        abort_unless(array_diff(array_keys($request->all()),
            ['method', 'card_number', 'expiry', 'cvv', 'idempotency_key']) === [], 422);
        // Exactly the existing B3.2 synthetic card scenarios and expiry/CVV.
        $input = $request->validate([
            'method' => ['required', 'in:card,cash'],
            'card_number' => ['required_if:method,card', 'prohibited_if:method,cash',
                'in:4242424242424242,4000000000000002,4000000000000119'],
            'expiry' => ['required_if:method,card', 'prohibited_if:method,cash', 'in:12/30'],
            'cvv' => ['required_if:method,card', 'prohibited_if:method,cash', 'in:123'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $scenario = $input['method'] === 'cash' ? 'awaiting_cash' : match ($input['card_number']) {
            '4000000000000002' => 'declined',
            '4000000000000119' => 'temporary_failure',
            default => 'test_approved',
        };
        $store->submit($request->user('sanctum'), $context, $input['method'], $scenario, $input['idempotency_key']);
        $fresh = $checkout->forReservation($request->user('sanctum'), $reservation);

        return $this->respond($fresh, $store->current($reservation));
    }

    private function respond(array $context, ?array $attempt): JsonResponse
    {
        $amount = $context['amount'];
        $eligible = $context['future_payment']['commercial_eligible']
            && ($context['hold']['remaining_seconds'] ?? 0) > 0;
        $status = $attempt['status'] ?? 'not_selected';

        return response()->json(['data' => [
            'reservation_id' => $context['reservation']['id'],
            'method' => $attempt['method'] ?? null,
            'status' => $status,
            'reference' => $attempt['reference'] ?? null,
            'can_choose' => $eligible && in_array($status, ['not_selected', 'declined', 'temporary_failure'], true),
            'can_confirm' => $eligible && in_array($status, ['test_approved', 'awaiting_cash', 'cash_received'], true)
                && ($context['confirmation_readiness']['state'] ?? null) === 'ready'
                && ($context['confirmation_readiness']['synthetic_web_requirement'] ?? null) === 'satisfied',
            'amount' => $amount ? [
                'currency' => 'MXN',
                'total_cents' => $amount->totalCents,
                'commission_cents' => $amount->commissionCents,
                'provider_cents' => $amount->providerCents,
                'commission_included' => true,
                'commission_basis_points' => $amount->commissionBasisPoints,
            ] : null,
            'server_now' => $context['server_now'],
            'expires_at' => $context['hold']['expires_at'] ?? null,
            'remaining_seconds' => $context['hold']['remaining_seconds'] ?? 0,
            'synthetic_only' => true,
        ]])->withHeaders(['Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }
}
