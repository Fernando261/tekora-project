<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Models\ProviderProfile;
use App\Models\ServiceCategory;
use App\Support\MarketplaceFilters;
use Illuminate\Database\Eloquent\Builder;

class PublicProviderController extends Controller
{
    public function index(CatalogFilterRequest $request)
    {
        $filters = $request->validated();
        $providers = MarketplaceFilters::providers(ProviderProfile::select(['id', 'user_id', 'display_name', 'headline', 'bio'])->withPublicCredential(), $filters)
            ->withCount(['services as published_services_count' => fn (Builder $services) => $services->publiclyVisible()])
            ->orderBy('display_name')->orderBy('id')->paginate(12)->appends($filters);

        return view('public-providers.index', compact('providers', 'filters') + ['categories' => ServiceCategory::active()->ordered()->get(['name', 'slug'])]);
    }

    public function show(CatalogFilterRequest $request, int $providerProfile)
    {
        $profile = ProviderProfile::select(['id', 'user_id', 'display_name', 'headline', 'bio'])->withPublicCredential()
            ->whereHas('services', fn (Builder $services) => $services->publiclyVisible())->findOrFail($providerProfile);
        $services = $profile->services()->publiclyVisible()->withPublishedPrice()->withMarketplaceDetails()
            ->orderByDesc('id')->paginate(12);
        $reviewPage = \App\Support\ReviewInput::page($request, 'reviews_page');
        $reviews = app(\App\Services\PublicReviews::class)->forProvider($profile->id, $reviewPage);
        if ($reviews) {
            $reviews['reviews']->appends(['page' => $services->currentPage()]);
        }
        $services->appends(['reviews_page' => $reviewPage]);

        return view('public-providers.show', compact('profile', 'services', 'reviews'));
    }
}
