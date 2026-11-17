<?php

namespace App\Providers;

use App\Payments\PricingStore;
use App\Payments\SqlitePricingStore;
use App\Payments\SyntheticQuotes;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PricingStore::class, fn ($app) => new SqlitePricingStore($app['db']->connection()));
        $this->app->bind(SyntheticQuotes::class, fn ($app) => new SyntheticQuotes($app->make(PricingStore::class), (int) config('quotes.lifetime_seconds')));
    }
}
