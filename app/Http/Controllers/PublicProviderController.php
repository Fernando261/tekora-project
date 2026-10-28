<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Builder;

class PublicProviderController extends Controller
{
    public function index(CatalogFilterRequest $request)
    {
        $providers = ProviderProfile::select(['id', 'display_name', 'headline', 'bio'])
            ->whereHas('services', fn (Builder $services) => $services->publiclyVisible())
            ->withCount(['services as published_services_count' => fn (Builder $services) => $services->publiclyVisible()])
            ->orderBy('display_name')->orderBy('id')->paginate(12);

        return view('public-providers.index', compact('providers'));
    }

    public function show(CatalogFilterRequest $request, int $providerProfile)
    {
        $profile = ProviderProfile::select(['id', 'display_name', 'headline', 'bio'])
            ->whereHas('services', fn (Builder $services) => $services->publiclyVisible())->findOrFail($providerProfile);
        $services = $profile->services()->publiclyVisible()->with(['category:id,name', 'providerProfile:id,display_name'])
            ->orderByDesc('id')->paginate(12);

        return view('public-providers.show', compact('profile', 'services'));
    }
}
