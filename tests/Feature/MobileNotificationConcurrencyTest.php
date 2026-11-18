<?php

namespace Tests\Feature;

use App\Exceptions\NotificationFailure;
use App\Models\User;
use App\Services\NotificationInbox;
use App\Services\ReservationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class MobileNotificationConcurrencyTest extends TestCase
{
    use MobileOperationsFixtures;

    private function shared(): array
    {
        $uri = 'file:tekora-m1-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['m1_a', 'm1_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('m1_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'm1_a', '--force' => true]));
        $this->assertNotSame(DB::connection('m1_a')->getPdo(), DB::connection('m1_b')->getPdo());
        foreach (['m1_a', 'm1_b'] as $name) {
            $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        }
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);

        return [$client, $provider];
    }

    public static function sources(): array
    {
        return [['inquiry'], ['activity']];
    }

    #[DataProvider('sources')]
    public function test_overlapping_read_attempts_from_two_pdos_conflict_then_retry_without_replacing_the_first_timestamp(string $source): void
    {
        [$client] = $this->shared();
        $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
        $notice = DB::table($table)->where('user_id', $client->id)->sole();
        $before = $this->reservationRows(['service_inquiries', 'service_reservations', 'reservation_events', 'service_reviews', 'service_inquiry_messages']);
        DB::setDefaultConnection('m1_b');
        $other = User::findOrFail($client->id);
        DB::setDefaultConnection('m1_a');
        $attempted = false;
        $conflict = false;
        $uncommittedReadAt = 'not observed';
        DB::connection('m1_a')->beforeExecuting(function ($sql) use (&$attempted, &$conflict, &$uncommittedReadAt, $source, $table, $other, $notice) {
            if ($attempted || ! str_starts_with($sql, 'update "'.$table.'"')) {
                return;
            }
            $attempted = true;
            DB::setDefaultConnection('m1_b');
            try {
                $uncommittedReadAt = app(NotificationInbox::class)->clientNotice($other, $source, (string) $notice->id)->read_at;
                app(NotificationInbox::class)->readClient($other, $source, (string) $notice->id);
            } catch (NotificationFailure $error) {
                $conflict = $error->status === 409;
            } finally {
                DB::setDefaultConnection('m1_a');
            }
        });
        $first = app(NotificationInbox::class)->readClient($client, $source, (string) $notice->id);
        $this->assertTrue($attempted);
        $this->assertTrue($conflict);
        $this->assertNull($uncommittedReadAt);
        DB::setDefaultConnection('m1_b');
        $this->travel(1)->hour();
        $this->postJson('/api/v1/notifications/'.$source.'/'.$notice->id.'/read', [], $this->bearer($other))->assertOk()
            ->assertJsonPath('data.read_at', CarbonImmutable::parse($first->read_at, 'UTC')->toISOString());
        $this->assertSame($first->read_at, DB::table($table)->where('id', $notice->id)->value('read_at'));
        $this->assertSame($before, $this->reservationRows(array_keys($before)));
        $this->assertSame(1, app(NotificationInbox::class)->clientUnreadCount($other));
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_list_snapshot_blocks_a_concurrent_read_and_keeps_filtered_total_and_rows_consistent(): void
    {
        [$client] = $this->shared();
        DB::setDefaultConnection('m1_b');
        $other = User::findOrFail($client->id);
        DB::setDefaultConnection('m1_a');
        $attempted = false;
        $conflict = false;
        DB::listen(function ($event) use (&$attempted, &$conflict, $other) {
            if ($attempted || $event->connectionName !== 'm1_a' || ! str_contains($event->sql, 'notification_feed')) {
                return;
            }
            $attempted = true;
            DB::setDefaultConnection('m1_b');
            try {
                app(NotificationInbox::class)->readClient($other, 'inquiry', '2');
            } catch (NotificationFailure $error) {
                $conflict = $error->status === 409;
            } finally {
                DB::setDefaultConnection('m1_a');
            }
        });
        $page = app(NotificationInbox::class)->clientListing($client);
        $this->assertTrue($attempted);
        $this->assertTrue($conflict);
        $this->assertSame(2, $page->total());
        $this->assertCount(2, $page);
        foreach ($page as $row) {
            $this->assertNull($row->read_at);
        }
        DB::setDefaultConnection('m1_b');
        app(NotificationInbox::class)->readClient($other, 'inquiry', '2');
        $this->assertSame(1, app(NotificationInbox::class)->clientUnreadCount($other));
    }
}
