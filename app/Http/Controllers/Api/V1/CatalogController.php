<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\AvailabilityResource;
use App\Http\Resources\Api\V1\ProviderResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ProviderAvailability;
use App\Support\MarketplaceFilters;
use App\Support\Municipalities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class CatalogController extends Controller
{
    private function services(): Builder
    {
        return Service::publiclyVisible()->withMarketplaceDetails();
    }

    private function providers(): Builder
    {
        return ProviderProfile::select(['id', 'user_id', 'display_name', 'headline', 'bio'])->withPublicCredential()
            ->whereHas('services', fn (Builder $services) => $services->publiclyVisible());
    }

    public function index(CatalogRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $query = MarketplaceFilters::services($this->services(), $filters);
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 12);

        return new ApiCollection($page->through(fn ($service) => new ServiceResource($service)));
    }

    public function show(CatalogRequest $request, int $service): ServiceResource
    {
        return new ServiceResource($this->services()->findOrFail($service));
    }

    public function categories(CatalogRequest $request): JsonResponse
    {
        return response()->json(['data' => ServiceCategory::active()->ordered()->get(['id', 'name', 'slug'])]);
    }

    public function municipalities(CatalogRequest $request): JsonResponse
    {
        return response()->json(['data' => Municipalities::rows()]);
    }

    public function providersIndex(CatalogRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $query = MarketplaceFilters::providers($this->providers(), $filters);
        $page = $query->orderBy('display_name')->orderBy('id')->paginate($filters['per_page'] ?? 12);

        return new ApiCollection($page->through(fn ($provider) => new ProviderResource($provider)));
    }

    public function provider(CatalogRequest $request, int $provider): ProviderResource
    {
        return new ProviderResource($this->providers()->findOrFail($provider));
    }

    public function providerServices(CatalogRequest $request, string $provider): ApiCollection
    {
        $id = filter_var($provider, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $profile = $this->providers()->findOrFail($id);
        $page = MarketplaceFilters::services($this->services()->where('provider_profile_id', $profile->id), $request->validated())
            ->orderByDesc('id')->paginate($request->validated('per_page', 12));

        return new ApiCollection($page->through(fn ($service) => new ServiceResource($service)));
    }

    public function availability(CatalogRequest $request, int $service, ProviderAvailability $availability): AvailabilityResource
    {
        $service = $this->services()->findOrFail($service);
        $public = $availability->publicFor($service->providerProfile);
        abort_if(isset($public['unavailable']), 503);

        return new AvailabilityResource(['service_id' => $service->id, 'availability' => $public]);
    }
}
