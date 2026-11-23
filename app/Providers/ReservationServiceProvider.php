<?php

namespace App\Providers;

use App\Database\BookingSQLiteConnector;
use Illuminate\Support\ServiceProvider;

class ReservationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.sqlite', BookingSQLiteConnector::class);
    }
}
