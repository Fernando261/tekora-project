<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

class ProviderDashboardPerformanceTest extends TestCase
{
    use DashboardFixtures;

    public function test_agenda_and_attention_are_limited_ordered_and_authorized_with_constant_query_count(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $workflow = app(ReservationWorkflow::class);
        $reservation = $this->proposeReservation($inquiry); $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->inquiry(null, $provider);
        DB::enableQueryLog(); DB::flushQueryLog(); $small = $this->dashboard($provider, 90); $smallQueries = DB::getQueryLog(); DB::disableQueryLog();
        $expectedReservations = [$reservation];
        foreach (['2030-01-10', '2030-01-05', '2030-01-04', '2030-01-06', '2030-01-09', '2030-01-03', '2030-01-08'] as $date) {
            $other = $this->inquiry(null, $provider, InquiryStatus::Accepted); $r = $this->proposeReservation($other, compact('date'));
            $workflow->decide($provider, $r->id, 'confirm', ['version' => 1]); $expectedReservations[] = $r;
        }
        $pending = [];
        foreach (range(1, 10) as $i) { $this->travel(1)->minutes(); $pending[] = $this->inquiry(null, $provider); }
        $this->message($pending[0]->client, $pending[0], 'NEVER-SELECT-THIS-BODY');
        DB::enableQueryLog(); DB::flushQueryLog(); $large = $this->dashboard($provider, 90); $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(count($smallQueries), count($queries)); $this->assertLessThanOrEqual(11, count($queries));
        $this->assertCount(5, $large['agenda']); $this->assertCount(5, $large['attention']); $this->assertCount(90, $large['series']);
        usort($expectedReservations, fn ($a, $b) => [$a->starts_at_utc, $a->id] <=> [$b->starts_at_utc, $b->id]);
        $this->assertSame(array_map(fn ($r) => $r->id, array_slice($expectedReservations, 0, 5)), array_column($large['agenda'], 'id'));
        $this->assertSame(8, $large['upcoming']); $this->assertSame(11, $large['pending']);
        $this->assertSame(array_merge([$small['attention'][0]['id']], array_map(fn ($r) => $r->id, array_slice($pending, 0, 4))), array_column($large['attention'], 'id'));
        foreach ($expectedReservations as $r) $this->assertTrue(Gate::forUser($provider)->allows('view', $r));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(comment|body|email)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/^select\s+\*/i', $query['query']);
        }
        $sql = implode("\n", array_column($queries, 'query')); $this->assertStringContainsString('limit 5', $sql);
        $this->assertStringContainsString('sum("unread_messages_count")', $sql);
        $this->assertDatabaseCount('service_inquiry_reads', 0);
    }
}
