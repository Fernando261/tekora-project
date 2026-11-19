<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Middleware\CheckoutDemoOnly;
use App\Models\ServiceReservation;
use App\Payments\CheckoutContext;
use App\Payments\LocalCheckoutAttemptStore;
use App\Payments\WebMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class LocalIntegratedCheckoutController extends Controller
{
    /** Only a newly generated SQLite fixture within the system temp directory. */
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
        abort_unless(Schema::hasTable('synthetic_checkout_attempts'), 404);
    }

    private function context(Request $request, int $reservation, CheckoutContext $checkout): array
    {
        $this->guard($request);
        $context = $checkout->forReservation($request->user('web'), $reservation);
        abort_unless($context['amount'] !== null && $context['quote'] !== null, 409);

        return $context;
    }

    public function show(Request $request, int $reservation, CheckoutContext $checkout, LocalCheckoutAttemptStore $store)
    {
        $context = $this->context($request, $reservation, $checkout);

        return response()->view('checkout.local-integrated', [
            'context' => $context,
            'reservation' => $reservation,
            'attempt' => $store->current($reservation),
            'formattedAmount' => WebMoney::format($context['amount']->totalCents),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function submit(Request $request, int $reservation, CheckoutContext $checkout, LocalCheckoutAttemptStore $store)
    {
        $context = $this->context($request, $reservation, $checkout);
        $eligible = $context['future_payment']['commercial_eligible']
            && ($context['hold']['remaining_seconds'] ?? 0) > 0;
        abort_unless($eligible, 409);
        abort_if(array_diff(array_keys($request->except('_token')),
            ['method', 'card_number', 'expiry', 'cvv', 'idempotency_key']) !== [], 422);
        $data = $request->validate([
            'method' => ['required', 'in:card,cash'],
            'card_number' => ['required_if:method,card', 'prohibited_if:method,cash',
                'in:4242424242424242,4000000000000002,4000000000000119'],
            'expiry' => ['required_if:method,card', 'prohibited_if:method,cash', 'in:12/30'],
            'cvv' => ['required_if:method,card', 'prohibited_if:method,cash', 'in:123'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $scenario = $data['method'] === 'cash' ? 'awaiting_cash'
            : match ($data['card_number']) {
                '4000000000000002' => 'declined',
                '4000000000000119' => 'temporary_failure',
                default => 'test_approved',
            };
        $store->submit($request->user('web'), $context, $data['method'], $scenario, $data['idempotency_key']);

        return redirect()->route('checkout.local.show', ['reservation' => $reservation], 303);
    }

    private function providerReservation(Request $request, int $reservation): ServiceReservation
    {
        $this->guard($request);
        $item = ServiceReservation::with(['providerProfile', 'hold'])->findOrFail($reservation);
        $actor = $request->user('web');
        Gate::forUser($actor)->authorize('view', $item);
        abort_unless($actor->hasPermission(Permission::ManageReceivedInquiries)
            && $item->providerProfile->user_id === $actor->id, 403);

        return $item;
    }

    public function cash(Request $request, int $reservation, LocalCheckoutAttemptStore $store)
    {
        $item = $this->providerReservation($request, $reservation);
        $attempt = $store->current($item->id);
        if ($attempt && ($attempt['method'] !== 'cash'
            || (int) $attempt['provider_user_id'] !== $request->user('web')->id)) {
            $attempt = null;
        }

        return response()->view('checkout.local-cash', ['reservation' => $reservation,
            'attempt' => $attempt])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function receiveCash(Request $request, int $reservation, LocalCheckoutAttemptStore $store)
    {
        abort_if(array_diff(array_keys($request->except('_token')), []) !== [], 422);
        $item = $this->providerReservation($request, $reservation);
        $store->receiveCash($request->user('web'), $item);

        return redirect()->route('checkout.local.cash', ['reservation' => $reservation], 303);
    }
}
