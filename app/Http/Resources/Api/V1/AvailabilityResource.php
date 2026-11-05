<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProviderWeeklyInterval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $availability = $this->resource['availability'];
        $intervals = [];
        foreach ($availability['days'] ?? [] as $day => $rows) {
            foreach ($rows as $row) {
                $intervals[] = ['weekday' => (int) $day,
                    'start_local' => ProviderWeeklyInterval::civilTime($row->start_minute),
                    'end_local' => ProviderWeeklyInterval::civilTime($row->end_minute)];
            }
        }

        return ['service_id' => $this->resource['service_id'], 'configured' => $availability !== null,
            'timezone' => $availability['timezone'] ?? null, 'kind' => 'informational_weekly_schedule',
            'intervals' => $intervals, 'blocked_dates' => $availability ? $availability['blocked_dates']->values()->all() : [],
            'queried_at' => now('UTC')->toISOString()];
    }
}
