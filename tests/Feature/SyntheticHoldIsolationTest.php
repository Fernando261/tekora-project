<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldStore;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SyntheticHoldEngine;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InMemorySlotHoldStore;
use Tests\Support\MutableSlotHoldClock;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

#[Group('slot-holds-b21')]
final class SyntheticHoldIsolationTest extends TestCase
{
    use ReservationFixtures;

    public function test_domain_commands_issue_no_sql_and_preserve_existing_reservation_fixture(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $tables = ['service_reservations', 'reservation_events', 'service_inquiries', 'services', 'inquiry_notifications', 'activity_notifications'];
        $before = $this->reservationRows($tables);
        $store = new InMemorySlotHoldStore;
        $clock = new MutableSlotHoldClock(new DateTimeImmutable('2030-01-01T12:00:00Z'));
        $engine = new SyntheticHoldEngine($store, $clock);
        $start = new DateTimeImmutable('2030-01-07T15:00:00Z');
        $proposal = new ReservationProposal($reservation->id, 1, new UtcInterval($start, $start->modify('+1 hour')),
            new AcceptedQuoteReference('synthetic-quote-only', $inquiry->id, $inquiry->service_id,
                $reservation->provider_profile_id, $provider->id, $client->id));
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $hold = $engine->accept($proposal, HoldActor::provider($provider->id), '00000000-0000-4000-8000-000000000001');
        $engine->commit($hold->id, HoldActor::system(), 1, '00000000-0000-4000-8000-000000000002');
        $this->assertSame([], $connection->getQueryLog());
        $connection->disableQueryLog();
        $this->assertSame($before, $this->reservationRows($tables));
        $this->assertSame('requested', $reservation->fresh()->status->value);
        $this->assertSame('accepted', $inquiry->fresh()->status->value);
    }

    public function test_no_store_binding_no_routes_and_no_migration_or_api_change(): void
    {
        $this->assertFalse(app()->bound(HoldStore::class));
        $this->assertCount(37, glob(database_path('migrations/*.php')));
        $api = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($api['paths'] as $path) {
            $count += count(array_intersect(array_keys($path), ['get', 'post', 'put', 'patch', 'delete']));
        }
        $this->assertSame(21, $count);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('App\\Scheduling\\Holds\\', $route->getActionName());
        }
        $this->assertSame('1.1.0', $api['info']['version']);
    }
}
