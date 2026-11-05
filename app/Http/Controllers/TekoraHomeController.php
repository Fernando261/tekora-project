<?php

namespace App\Http\Controllers;

use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TekoraHomeController extends Controller
{
    public function __invoke()
    {
        return DB::transaction(function () {
            $categories = ServiceCategory::active()->ordered()->get(['id', 'name', 'slug']);
            $services = Service::publiclyVisible()->select(['id', 'title', 'description', 'modality', 'service_category_id', 'provider_profile_id'])
                ->with(['category:id,name', 'providerProfile:id,display_name'])->orderByDesc('id')->limit(3)->get();
            $providers = ProviderProfile::select(['id', 'display_name', 'headline'])
                ->whereHas('services', fn (Builder $services) => $services->publiclyVisible())
                ->orderBy('display_name')->orderBy('id')->limit(3)->get();

            return response()->view('tekora.home', compact('categories', 'services', 'providers'));
        }, 3);
    }
}
