<?php

// CLI-only comparison over disposable SQLite :memory: fixtures.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/tests/bootstrap.php';
class W2Benchmark extends \Tests\TestCase
{
    use \Tests\Support\DashboardFixtures;

    public function measure(): void
    {
        $this->setUp();
        [$client,$provider,$inquiry,$reservation] = $this->reviewContext();
        $this->publishReview($reservation);
        $this->travelTo(\Carbon\Carbon::parse('2030-01-10 12:00:00', 'UTC'));
        $own = $this->inquiry($provider, null, \App\Enums\InquiryStatus::Accepted);
        $this->publishSchedule($own->service->providerProfile->user);
        $proposed = $this->proposeReservation($own, ['date' => '2030-01-14']);
        app(\App\Services\ReservationWorkflow::class)->decide($own->service->providerProfile->user, $proposed->id, 'confirm', ['version' => 1]);
        $results = [];
        foreach ([['client', 'account.client'], ['provider', 'provider.dashboard']] as [$name,$route]) {
            $this->actingAs($provider, 'web')->get(route($route))->assertOk();
            $runs = [];
            for ($i = 0; $i < 3; $i++) {
                \Illuminate\Support\Facades\DB::enableQueryLog();
                \Illuminate\Support\Facades\DB::flushQueryLog();
                $start = hrtime(true);
                $this->actingAs($provider, 'web')->get(route($route))->assertOk();
                $queries = \Illuminate\Support\Facades\DB::getQueryLog();
                \Illuminate\Support\Facades\DB::disableQueryLog();
                $runs[] = ['queries' => count($queries), 'milliseconds' => round((hrtime(true) - $start) / 1e6, 2), 'sql_ms' => round(array_sum(array_column($queries, 'time')), 2)];
            }
            $results[$name] = $runs;
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        app(\App\Services\ProviderDashboardMetrics::class)->build($provider, ['period' => '30']);
        $q = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $results['provider_service_queries'] = count($q);
        echo json_encode($results, JSON_PRETTY_PRINT),PHP_EOL;
        \Illuminate\Support\Facades\DB::disconnect();
        \Carbon\Carbon::setTestNow();
    }
}
(new W2Benchmark('measure'))->measure();
