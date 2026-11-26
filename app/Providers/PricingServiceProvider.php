<?php

namespace App\Providers;

use App\Database\PricingMySqlConnection;
use App\Payments\MysqlPricingStore;
use App\Payments\PricingStore;
use App\Payments\SqlitePricingStore;
use App\Payments\SyntheticQuotes;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LogicException;

class PricingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Laravel suppresses Symfony console events in testing. The MySQL guard also applies to test CLIs.
            $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        }

        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) {
            $command = $event->command;
            if ($command === null || ($command !== 'db:wipe' && $command !== 'migrate' && ! str_starts_with($command, 'migrate:'))
                || $command === 'migrate:status') {
                return;
            }
            $name = $event->input->getParameterOption('--database', config('database.default'));
            if (config('database.default') === 'mysql' || config('database.connections.'.$name.'.driver') === 'mysql') {
                throw new LogicException('MySQL requiere tekora:mysql:provision. Las operaciones de migración ordinarias están bloqueadas.');
            }
        });
    }

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
