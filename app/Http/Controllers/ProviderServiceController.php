<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Http\Requests\ServiceRequest;
use App\Http\Requests\ServiceStatusRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProviderServiceController extends Controller
{
    public function index(Request $request)
    {
        Gate::forUser($request->user('web'))->authorize('viewAny', Service::class);
        $profile = $request->user('web')->providerProfile()->first();
        $services = $profile?->services()->withPublishedPrice()->with('category:id,name,is_active')->orderByDesc('id')->paginate(12);

        return view('provider-services.index', compact('profile', 'services'));
    }

    public function create(Request $request)
    {
        Gate::forUser($request->user('web'))->authorize('create', Service::class);

        return view('provider-services.form', ['service' => new Service(['status' => ServiceStatus::Draft]),
            'categories' => ServiceCategory::active()->ordered()->get()]);
    }

    public function store(ServiceRequest $request)
    {
        $profile = $request->user('web')->providerProfile()->firstOrFail();
        $details = $request->safe()->only(['title', 'description', 'service_category_id', 'modality', 'status', 'municipality']);
        if ($request->filled('price_mxn')) {
            \App\Payments\PricingInput::only($request->except('_token'), ['title', 'description', 'service_category_id', 'modality', 'status', 'municipality', 'price_mxn', 'creation_key']);
            $cents = \App\Payments\WebMoney::cents($request->input('price_mxn'));
            // Normalize category identity so HTTP retries have a stable intent fingerprint.
            $details['service_category_id'] = (int) $details['service_category_id'];

            return \App\Payments\PricingResponse::run($request, function () use ($request, $details, $cents) {
                $service = app(\App\Payments\CreatePricedService::class)->create($request->user('web'), $details, $cents, $request->validated('creation_key'));

                return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Servicio y precio publicados.');
            });
        }
        $service = DB::transaction(function () use ($profile, $details) {
            $service = $profile->services()->create(Arr::except($details, 'municipality'));
            $service->syncMunicipality($details['municipality'] ?? null);

            return $service;
        });

        return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Servicio creado. Estado: '.$service->status->label().'.');
    }

    public function edit(Request $request, Service $service)
    {
        Gate::forUser($request->user('web'))->authorize('update', $service);

        if (\App\Support\TrustSchema::available()) {
            $service->load('location');
        }

        return view('provider-services.form', ['service' => $service, 'price' => app(\App\Payments\PricingStore::class)->latestPrice($service->id),
            'categories' => ServiceCategory::active()->ordered()->get()]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        DB::transaction(function () use ($request, $service) {
            $current = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($request->user('web'))->authorize('update', $current);
            $current->update($request->safe()->except('municipality'));
            $current->syncMunicipality($request->validated('municipality'));
        });

        return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Servicio actualizado. Estado: '.$service->status->label().'.');
    }

    public function status(ServiceStatusRequest $request, Service $service)
    {
        // Solo se cambia el estado; los campos extra nunca se asignan.
        $service->update($request->safe()->only('status'));

        return redirect()->route('provider-services.index', [], 303)->with('success', 'Estado del servicio: '.$service->status->label().'.');
    }
}
