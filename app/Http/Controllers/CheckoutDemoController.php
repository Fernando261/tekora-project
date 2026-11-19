<?php

namespace App\Http\Controllers;

use App\Demo\CheckoutStore;
use App\Payments\CommercialAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CheckoutDemoController extends Controller
{
    private const CLIENT = 'tekora_checkout_client';

    private const OPERATOR = 'tekora_checkout_operator';

    public function __invoke(Request $request, CheckoutStore $store)
    {
        $this->only($request, []);
        $cookie = null;
        if ($request->cookie(self::CLIENT)) {
            $data = $store->client($request->cookie(self::CLIENT), fn (&$state) => $store->view($state));
        } else {
            [$cookie, $data] = $store->create();
        }
        $response = response()->view('demo.checkout', ['amount' => CommercialAmount::fromCents($data['quote']['amount']['total_cents']),
            'exampleDate' => \Carbon\CarbonImmutable::parse($data['starts_at'])->setTimezone('America/Mexico_City')->format('d/m/Y'), 'demo' => $data]);

        return $cookie ? $response->withCookie($this->cookie($request, self::CLIENT, $cookie)) : $response;
    }

    public function context(Request $request, CheckoutStore $store)
    {
        $this->only($request, []);

        return $this->client($request, $store, fn (&$state) => $store->view($state, $request->route('attempt')));
    }

    public function submit(Request $request, CheckoutStore $store)
    {
        return $this->client($request, $store, function (&$state) use ($request, $store) {
            $this->csrf($request, $state['csrf']);
            $input = $this->only($request, ['method', 'instrument', 'scenario', 'idempotency_key']);
            $data = Validator::make($input, ['method' => ['required', Rule::in(['card', 'cash'])],
                'instrument' => ['required_if:method,card', 'prohibited_if:method,cash', Rule::in(['debit', 'credit'])],
                'scenario' => ['required', Rule::in(['success', 'declined', 'temporary'])], 'idempotency_key' => ['required', 'uuid']])->validate();

            return $store->submit($state, $data);
        });
    }

    public function retry(Request $request, CheckoutStore $store)
    {
        return $this->client($request, $store, function (&$state) use ($request, $store) {
            $this->csrf($request, $state['csrf']);
            $this->only($request, []);

            return $store->retry($state, $request->route('attempt'));
        });
    }

    public function fresh(Request $request, CheckoutStore $store)
    {
        $this->client($request, $store, function (&$state) use ($request) {
            $this->csrf($request, $state['csrf']);
            $this->only($request, []);

            return [];
        });
        [$cookie, $data] = $store->create();

        return response()->json(['data' => $data])->withCookie($this->cookie($request, self::CLIENT, $cookie));
    }

    public function operatorAccess(Request $request, CheckoutStore $store)
    {
        $data = $this->only($request, ['token']);
        $token = $data['token'] ?? '';
        abort_unless(is_string($token) && $store->operator($token), 403);

        return redirect()->route('demo.checkout.provider')->withCookie($this->cookie($request, self::OPERATOR, $token));
    }

    private function operator(Request $request, CheckoutStore $store): string
    {
        $token = $request->cookie(self::OPERATOR, '');
        abort_unless(is_string($token) && $store->operator($token), 403);

        return hash_hmac('sha256', 'provider-demo-csrf', $token);
    }

    public function provider(Request $request, CheckoutStore $store)
    {
        $csrf = $this->operator($request, $store);
        $this->only($request, []);

        return response()->view('demo.checkout-provider', compact('csrf'));
    }

    public function pending(Request $request, CheckoutStore $store)
    {
        $this->operator($request, $store);
        $this->only($request, []);

        return response()->json(['data' => $store->pending()]);
    }

    public function receive(Request $request, CheckoutStore $store)
    {
        $this->csrf($request, $this->operator($request, $store));
        $this->only($request, []);

        return response()->json(['data' => $store->receiver($request->route('checkout'), fn (&$state) => $store->receive($state, $request->route('attempt')))]);
    }

    private function client(Request $request, CheckoutStore $store, callable $operation)
    {
        $credential = $request->cookie(self::CLIENT, '');
        abort_unless(is_string($credential), 401);

        return response()->json(['data' => $store->client($credential, $operation)]);
    }

    private function csrf(Request $request, string $expected): void
    {
        abort_unless(is_string($request->header('X-Checkout-Demo-CSRF')) && hash_equals($expected, $request->header('X-Checkout-Demo-CSRF')), 419);
        abort_if($request->header('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost(), 419);
    }

    private function only(Request $request, array $allowed): array
    {
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 422);

        return $request->all();
    }

    private function cookie(Request $request, string $name, string $value)
    {
        return cookie($name, $value, 60, '/demo/checkout', null, $request->isSecure(), true, false, 'strict');
    }
}
