<?php

namespace App\Http\Controllers;

use App\Enums\ServiceModality;
use App\Http\Requests\CatalogFilterRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;

class ServiceCatalogController extends Controller
{
    public function index(CatalogFilterRequest $request)
    {
        $filters = $request->validated();
        $query = Service::publiclyVisible()->with(['category:id,name,slug', 'providerProfile:id,display_name'])
            ->select(['id', 'title', 'description', 'modality', 'provider_profile_id', 'service_category_id']);

        if (isset($filters['q']) && $filters['q'] !== '') {
            // Parámetro enlazado; %, _ y ! se buscan literalmente en vez de ampliar el LIKE.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn (Builder $search) => $search->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", [$term]));
        }
        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $categories) => $categories->where('slug', $filters['category']));
        }
        if (! empty($filters['modality'])) {
            $query->whereIn('modality', array_unique([$filters['modality'], ServiceModality::Both->value]));
        }

        return view('catalog.index', ['services' => $query->orderByDesc('id')->paginate(12)->appends($filters),
            'categories' => ServiceCategory::active()->ordered()->get(['name', 'slug']), 'filters' => $filters]);
    }

    public function show(int $service)
    {
        $service = Service::publiclyVisible()->with(['category:id,name', 'providerProfile:id,display_name,headline,bio'])
            ->findOrFail($service);

        return view('catalog.show', compact('service'));
    }
}
