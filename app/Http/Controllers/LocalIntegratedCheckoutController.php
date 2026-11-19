<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckoutDemoOnly;
use App\Payments\CheckoutContext;
use App\Payments\WebMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class LocalIntegratedCheckoutController extends Controller
{
    /**
     * Strictly local, synthetic SQLite-only path. No gateway, ledger or B2 changes.
     */
    private function guard(Request $request): void
    {
        abort_unless(CheckoutDemoOnly::permitsLocalRequest($request), 404);
        abort_unless(config('database.default') === 'sqlite', 404);
        $database = config('database.connections.sqlite.database');
        $root = realpath(sys_get_temp_dir());
        $path = is_string($database) ? realpath($database) : false;
        abort_unless($root && $path && is_file($path)
            && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/tekora-b32-'), 404);
        abort_unless(is_file(dirname($path).DIRECTORY_SEPARATOR.'.tekora-b32-synthetic'), 404);
    }

    private function context(Request $request, int $reservation, CheckoutContext $checkout): array
    {
        $this->guard($request);
        $context = $checkout->forReservation($request->user('web'), $reservation);
        abort_unless($context['amount'] !== null, 409);
        return $context;
    }

    public function show(Request $request, int $reservation, CheckoutContext $checkout)
    {
        $context = $this->context($request, $reservation, $checkout);
        $attempt = $request->session()->get('tekora_b32_checkout.'.$reservation);
        return response()->view('checkout.local-integrated', [
            'context' => $context,
            'reservation' => $reservation,
            'attempt' => is_array($attempt) ? $attempt : null,
            'formattedAmount' => WebMoney::format($context['amount']->totalCents),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function submit(Request $request, int $reservation, CheckoutContext $checkout)
    {
        $context = $this->context($request, $reservation, $checkout);
        $eligible = $context['future_payment']['commercial_eligible']
            && ($context['hold']['remaining_seconds'] ?? 0) > 0;
        abort_unless($eligible, 409);
        $data = $request->validate([
            'method' => ['required', 'in:card,cash'],
            'card_number' => ['required_if:method,card', 'nullable', 'in:4242424242424242,4000000000000002,4000000000000119'],
            'expiry' => ['required_if:method,card', 'nullable', 'in:12/30'],
            'cvv' => ['required_if:method,card', 'nullable', 'in:123'],
        ]);
        $method = $data['method'];
        $status = $method === 'cash' ? 'awaiting_cash'
            : match ($data['card_number']) {
                '4000000000000002' => 'declined',
                '4000000000000119' => 'temporary_failure',
                default => 'test_approved',
            };
        $result = [
            'reference' => 'TK-TEST-'.strtoupper(Str::random(12)),
            'reservation_id' => $reservation,
            'quote_id' => $context['quote']->quote->id,
            'amount_cents' => $context['amount']->totalCents,
            'method' => $method,
            'status' => $status,
            'created_at' => now('UTC')->toISOString(),
            'synthetic' => true,
        ];
        $request->session()->put('tekora_b32_checkout.'.$reservation, $result);
        return redirect()->route('checkout.local.show', ['reservation' => $reservation])
            ->with('checkout_notice', 'Se actualizó el resultado de prueba. No se ha efectuado ningún cobro.');
    }
}
