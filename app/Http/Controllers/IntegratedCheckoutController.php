<?php

namespace App\Http\Controllers;

use App\Payments\CheckoutContext;
use App\Payments\IntegratedCheckout;
use App\Payments\WebMoney;
use App\Support\BookingClock;
use Illuminate\Http\Request;

final class IntegratedCheckoutController extends Controller
{
    public function show(Request $request, int $reservation, IntegratedCheckout $checkout, CheckoutContext $context)
    {
        $actor = $request->user('web');
        $item = $checkout->clientReservation($actor, $reservation);
        $projection = $context->forReservation($actor, $reservation);
        abort_unless($projection['amount'], 503);
        $attempt = $checkout->latest($item);

        return $this->page('checkout.local-integrated', [
            'context' => $projection, 'reservation' => $reservation, 'attempt' => $attempt,
            'canReceiveCash' => IntegratedCheckout::enabled() && $checkout->cashWindow($item, BookingClock::observedMicros()),
            'formattedAmount' => WebMoney::format($projection['amount']->totalCents),
        ]);
    }

    public function submit(Request $request, int $reservation, IntegratedCheckout $checkout)
    {
        abort_unless($request->query() === [], 422);
        $checkout->submit($request->user('web'), $reservation, $request->except('_token'));

        return redirect()->route('checkout.integrated.show', $reservation, 303)->with('b32_checkout_result', true);
    }

    public function cash(Request $request, int $reservation, IntegratedCheckout $checkout)
    {
        $actor = $request->user('web');
        $item = $checkout->providerReservation($actor, $reservation);
        $summary = $checkout->providerSummary($actor, $item);

        return $this->page('checkout.local-cash', ['reservation' => $reservation,
            'attempt' => ($summary['method'] ?? null) === 'cash' ? $summary : null,
            'canReceiveCash' => $summary['can_receive_cash'] ?? false]);
    }

    public function receiveCash(Request $request, int $reservation, IntegratedCheckout $checkout)
    {
        abort_unless($request->except('_token') === [], 422);
        $checkout->receiveCash($request->user('web'), $reservation);

        return redirect()->route('checkout.integrated.cash', $reservation, 303);
    }

    private function page(string $view, array $data)
    {
        return response()->view($view, $data + ['officialCheckout' => true])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
