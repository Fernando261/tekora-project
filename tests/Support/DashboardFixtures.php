<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\ProviderDashboardMetrics;

trait DashboardFixtures
{
    use ReviewFixtures;

    protected function dashboard(User $provider, int $days = 30): array
    {
        return app(ProviderDashboardMetrics::class)->build($provider, ['period' => (string) $days]);
    }

    protected function values(array $dashboard): array
    {
        return array_column($dashboard['metrics'], 'value', 'id');
    }
}
