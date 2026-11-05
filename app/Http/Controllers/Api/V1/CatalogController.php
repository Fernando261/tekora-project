<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceModality;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\AvailabilityResource;
use App\Http\Resources\Api\V1\ProviderResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Services\ProviderAvailability;
use Illuminate\Database\Eloquent\Builder;

final class CatalogController extends Controller
{
    private function services(): Builder
    {
        return Service::publiclyVisible()->with(['category:id,name,slug', 'providerProfile:id,display_name,headline,bio']);
    }

    private function providers(): Builder
    {
        return ProviderProfile::select(['id', 'display_name', 'headline', 'bio'])
            ->whereHas('services', fn (Builder $services) => $services->publiclyVisible());
    }

    private function search(Builder $query, string $term, array $columns): void
    {
        $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $query->where(function (Builder $search) use ($literal, $columns) {
            foreach ($columns as $column) {
                $search->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$literal]);
            }
        });
    }

    public function index(CatalogRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $query = $this->services();
        if (isset($filters['q'])) {
            $this->search($query, $filters['q'], ['title', 'description']);
        }
        if (isset($filters['category'])) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }
        if (isset($filters['modality'])) {
            $query->whereIn('modality', array_unique([$filters['modality'], ServiceModality::Both->value]));
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 12);

        return new ApiCollection($page->through(fn ($service) => new ServiceResource($service)));
    }

    public function show(CatalogRequest $request, int $service): ServiceResource
    {
        return new ServiceResource($this->services()->findOrFail($service));
    }

    public function providersIndex(CatalogRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $query = $this->providers();
        if (isset($filters['q'])) {
            $this->search($query, $filters['q'], ['display_name', 'headline']);
        }
        $page = $query->orderBy('display_name')->orderBy('id')->paginate($filters['per_page'] ?? 12);

        return new ApiCollection($page->through(fn ($provider) => new ProviderResource($provider)));
    }

    public function provider(CatalogRequest $request, int $provider): ProviderResource
    {
        return new ProviderResource($this->providers()->findOrFail($provider));
    }

    public function availability(CatalogRequest $request, int $service, ProviderAvailability $availability): AvailabilityResource
    {
        $service = $this->services()->findOrFail($service);
        $public = $availability->publicFor($service->providerProfile);
        abort_if(isset($public['unavailable']), 503);

        return new AvailabilityResource(['service_id' => $service->id, 'availability' => $public]);
    }
}
