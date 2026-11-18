<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Payments\PricingInput;
use App\Payments\PricingResponse;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Payments\WebMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WebPricingController extends Controller
{
    public function publish(Request $request, Service $service, ServicePricing $pricing)
    {
        Gate::forUser($request->user('web'))->authorize('update', $service);
        PricingInput::only($request->except('_token'), ['price_mxn', 'expected_version', 'idempotency_key']);
        $cents = WebMoney::cents($request->input('price_mxn'));

        return PricingResponse::run($request, function () use ($request, $service, $pricing, $cents) {
            $pricing->publish($request->user('web'), $service->id, ['amount_cents' => $cents, 'expected_version' => $request->input('expected_version'), 'idempotency_key' => $request->input('idempotency_key')]);

            return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Precio publicado. Las cotizaciones anteriores conservan su importe.');
        });
    }

    public function issue(Request $request, ServiceInquiry $inquiry, SyntheticQuotes $quotes)
    {
        Gate::forUser($request->user('web'))->authorize('view', $inquiry);
        PricingInput::only($request->except('_token'), ['idempotency_key']);

        return PricingResponse::run($request, function () use ($request, $inquiry, $quotes) {
            $quote = $quotes->issue($request->user('web'), $inquiry->id, $request->only('idempotency_key'));

            return redirect()->route('quotes.show', $quote->id, 303);
        });
    }

    public function show(Request $request, string $quote, PricingStore $store)
    {
        return PricingResponse::run($request, function () use ($request, $quote, $store) {
            $quote = $store->quote($quote);
            abort_unless($quote, 404);
            $inquiry = ServiceInquiry::findOrFail($quote->inquiryId);
            Gate::forUser($request->user('web'))->authorize('view', $inquiry);
            $inquiry->load(['client:id,name', 'service.providerProfile:id,display_name']);
            $acceptance = $store->acceptance($quote->id);
            $publicService = Service::publiclyVisible()->whereKey($quote->serviceId)->exists();

            return response()->view('quotes.show', compact('quote', 'inquiry', 'acceptance', 'publicService'))->header('Cache-Control', 'no-store, private');
        });
    }

    public function accept(Request $request, string $quote, SyntheticQuotes $quotes)
    {
        // Authorize before validating the explicit acknowledgement.
        $quotes->view($request->user('web'), $quote);
        PricingInput::only($request->except('_token'), ['acknowledge']);
        $request->validate(PricingInput::acknowledgementRules());

        return PricingResponse::run($request, function () use ($request, $quote, $quotes) {
            $quotes->accept($request->user('web'), $quote);

            return redirect()->route('quotes.show', $quote, 303)->with('success', 'Cotización aceptada. No se ha creado una reserva ni realizado un pago.');
        });
    }
}
