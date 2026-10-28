<?php

namespace App\Http\Controllers;

use App\Enums\InquiryAction;
use App\Exceptions\InquiryConflict;
use App\Http\Requests\DecideInquiryRequest;
use App\Http\Requests\InquiryIndexRequest;
use App\Http\Requests\StoreInquiryRequest;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Services\InquiryWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServiceInquiryController extends Controller
{
    public function index(InquiryIndexRequest $request)
    {
        return $this->listing($request, false);
    }

    public function received(InquiryIndexRequest $request)
    {
        return $this->listing($request, true);
    }

    private function listing(InquiryIndexRequest $request, bool $received)
    {
        $user = $request->user('web');
        $query = ServiceInquiry::with(['client:id,name', 'service:id,provider_profile_id', 'service.providerProfile:id,display_name']);
        if ($received) {
            $query->whereHas('service.providerProfile', fn ($profiles) => $profiles->where('user_id', $user->id));
        } else {
            $query->where('client_user_id', $user->id);
        }
        $filters = $request->validated();
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        $inquiries = $query->orderByDesc('id')->paginate(12)->appends($filters);

        return view('inquiries.index', compact('inquiries', 'received', 'filters'));
    }

    public function create(Request $request, Service $service)
    {
        Gate::forUser($request->user('web'))->authorize('create', [ServiceInquiry::class, $service]);
        $service = Service::publiclyVisible()->with('providerProfile:id,display_name')->findOrFail($service->id);
        $existing = $request->user('web')->serviceInquiries()->where('service_id', $service->id)->active()->first();
        if ($existing) return redirect()->route('inquiries.show', $existing)->with('info', 'Ya tienes una solicitud pendiente o aceptada para este servicio.');

        return view('inquiries.create', compact('service'));
    }

    public function store(StoreInquiryRequest $request, Service $service, InquiryWorkflow $workflow)
    {
        try {
            $result = $workflow->open($request->user('web'), $service->id, $request->validated('message'));

            return redirect()->route('inquiries.show', $result['inquiry'], 303)->with($result['created'] ? 'success' : 'info',
                $result['created'] ? 'Solicitud enviada. Puedes consultar su seguimiento aquí.' : 'Ya tienes una solicitud activa. Se conserva el envío original.');
        } catch (InquiryConflict $conflict) {
            return $this->conflict($request, $conflict);
        }
    }

    public function show(Request $request, ServiceInquiry $inquiry)
    {
        Gate::forUser($request->user('web'))->authorize('view', $inquiry);
        $inquiry->load(['client:id,name', 'service:id,provider_profile_id', 'service.providerProfile:id,display_name']);
        $publicService = Service::publiclyVisible()->whereKey($inquiry->service_id)->exists();

        return view('inquiries.show', compact('inquiry', 'publicService'));
    }

    public function decide(DecideInquiryRequest $request, ServiceInquiry $inquiry, InquiryWorkflow $workflow)
    {
        // El tipo de decisión procede de los defaults fijos de la ruta, nunca del formulario.
        $action = InquiryAction::from($request->route()->defaults['decision']);
        try {
            $updated = $workflow->decide($request->user('web'), $inquiry->id, $action);

            return redirect()->route('inquiries.show', $updated, 303)->with('success', 'Estado de la solicitud: '.$updated->status->label().'.');
        } catch (InquiryConflict $conflict) {
            return $this->conflict($request, $conflict);
        }
    }

    private function conflict(Request $request, InquiryConflict $conflict)
    {
        $detailUrl = $conflict->inquiryId ? route('inquiries.show', $conflict->inquiryId) : route('inquiries.index');
        if ($request->expectsJson()) return response()->json(['message' => $conflict->getMessage(), 'inquiry_url' => $detailUrl], 409);

        return response()->view('inquiries.conflict', ['message' => $conflict->getMessage(), 'detailUrl' => $detailUrl], 409);
    }
}
