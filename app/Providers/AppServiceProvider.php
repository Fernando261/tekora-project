<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Enums\Permission;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Policies\ProviderProfilePolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceInquiryPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (Authenticatable $actor) =>
                $actor instanceof User && $actor->hasPermission($permission));
        }

        Gate::policy(ProviderProfile::class, ProviderProfilePolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceInquiry::class, ServiceInquiryPolicy::class);

        RateLimiter::for('service-inquiries', function (Request $request) {
            $id = $request->user('web')->getAuthIdentifier();
            $response = fn (Request $request, array $headers) => response()->view('inquiries.rate-limited', [], 429, $headers);

            return [
                Limit::perMinute(5)->by('inquiries:minute:'.$id)->response($response),
                Limit::perHour(30)->by('inquiries:hour:'.$id)->response($response),
                Limit::perMinute(20)->by('inquiries:ip:'.hash('sha256', $request->ip()))->response($response),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $identity = is_string($email) ? Str::lower(trim($email)) : '';
            $key = hash('sha256', $request->route()->getName().'|'.$identity.'|'.$request->ip());

            return [
                Limit::perMinute(5)->by('login-identity:'.$key),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
