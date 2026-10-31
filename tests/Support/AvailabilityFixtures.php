<?php

namespace Tests\Support;

use App\Models\ProviderAvailabilitySetting;

trait AvailabilityFixtures
{
    use ReadFixtures;

    protected function availabilityData(array $changes = []): array
    {
        return $changes + ['revision' => 0, 'timezone' => ProviderAvailabilitySetting::DEFAULT_TIMEZONE,
            'intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '12:00'],
                ['day_of_week' => 1, 'start' => '13:00', 'end' => '17:00'], ['day_of_week' => 2, 'start' => '10:00', 'end' => '15:00']],
            'blocked_dates' => ['2030-01-01', '2030-12-25']];
    }
}
