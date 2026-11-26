<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\MarketplaceFilters;

class ServiceCatalogController extends Controller
{
    public function index(CatalogFilterRequest $request)
    {
        $filters = $request->validated();
        $query = MarketplaceFilters::services(Service::publiclyVisible()->withMarketplaceDetails()->withPublishedPrice(), $filters);

        return view('catalog.index', ['services' => $query->orderByDesc('id')->paginate(12)->appends($filters),
            'categories' => ServiceCategory::active()->ordered()->get(['name', 'slug']), 'filters' => $filters]);
    }

    public function show(\Illuminate\Http\Request $request, int $service)
    {
        $service = Service::publiclyVisible()->withPublishedPrice()->withMarketplaceDetails()
            ->findOrFail($service);

        $reviewPage = \App\Support\ReviewInput::page($request, 'reviews_page');
        $reviews = app(\App\Services\PublicReviews::class)->forService($service->id, $reviewPage);

        return view('catalog.show', compact('service', 'reviews'));
    }
}
