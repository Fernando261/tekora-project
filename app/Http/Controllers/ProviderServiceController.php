<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Http\Requests\ServiceRequest;
use App\Http\Requests\ServiceStatusRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProviderServiceController extends Controller
{
    public function index(Request $request)
    {
        Gate::forUser($request->user('web'))->authorize('viewAny', Service::class);
        $profile = $request->user('web')->providerProfile()->first();
        $services = $profile?->services()->with('category:id,name,is_active')->orderByDesc('id')->paginate(12);

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
        $service = $profile->services()->create($request->validated());

        return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Servicio creado. Estado: '.$service->status->label().'.');
    }

    public function edit(Request $request, Service $service)
    {
        Gate::forUser($request->user('web'))->authorize('update', $service);

        return view('provider-services.form', ['service' => $service,
            'categories' => ServiceCategory::active()->ordered()->get()]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $service->update($request->validated());

        return redirect()->route('provider-services.edit', $service, 303)->with('success', 'Servicio actualizado. Estado: '.$service->status->label().'.');
    }

    public function status(ServiceStatusRequest $request, Service $service)
    {
        // Solo se cambia el estado; los campos extra nunca se asignan.
        $service->update($request->safe()->only('status'));

        return redirect()->route('provider-services.index', [], 303)->with('success', 'Estado del servicio: '.$service->status->label().'.');
    }
}
