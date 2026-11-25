<?php

namespace App\Providers;

use App\Database\PricingMySqlConnection;
use App\Payments\MysqlPricingStore;
use App\Payments\PricingStore;
use App\Payments\SqlitePricingStore;
use App\Payments\SyntheticQuotes;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LogicException;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new PricingMySqlConnection($pdo, $database, $prefix, $config));
        $this->app->scoped(PricingStore::class, function ($app) {
            $connection = $app['db']->connection();

            return match ($connection->getDriverName()) {
                'sqlite' => new SqlitePricingStore($connection),
                'mysql' => new MysqlPricingStore($connection),
                default => throw new LogicException('Motor de precios no soportado.'),
            };
        });
        $this->app->bind(SyntheticQuotes::class, fn ($app) => new SyntheticQuotes($app->make(PricingStore::class), (int) config('quotes.lifetime_seconds')));
    }
}
