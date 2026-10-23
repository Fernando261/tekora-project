<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Enums\Permission;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Policies\ProviderProfilePolicy;
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
