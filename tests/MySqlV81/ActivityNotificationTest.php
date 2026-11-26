<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Exceptions\ReviewFailure;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\NotificationInbox;
use App\Services\ReviewWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class ActivityNotificationTest extends LabTestCase
{
    private const CONTENT = ['rating' => 4, 'comment' => 'Comentario privado V8.1.5'];

    private const FIRST_READ = '2030-01-09 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2030, 1, 2)->setTime(12, 0, 0));
    }

    public function test_all_reservation_and_review_events_emit_once_to_the_correct_participant(): void
    {
        $reservation = $this->confirm($this->proposal());
        $client = $reservation->client;
        $provider = $reservation->providerProfile->user;
        $reservation = $this->transition($reservation, 'completed', 'complete', $provider);
        $this->assertNotices($reservation, ['reservation_requested' => $provider->id, 'reservation_confirmed' => $client->id,
            'reservation_completed' => $client->id]);
        foreach ([['rejected', 'reject', false, false], ['cancelled', 'cancel', true, true], ['cancelled', 'cancel', false, false],
            ['cancelled', 'inquiry_completed', false, false]] as [$state, $reason, $byClient, $afterConfirmation]) {
            $item = $this->proposal($client, $provider);
            $expected = ['reservation_requested' => $provider->id];
            if ($afterConfirmation) {
                $item = $this->confirm($item);
                $expected['reservation_confirmed'] = $client->id;
            }
            $this->transition($item, $state, $reason, $byClient ? $client : $provider);
            $this->assertNotices($item, $expected + ['reservation_'.$state => $byClient ? $provider->id : $client->id]);
        }

        $this->firstReadTime();
        $reviews = app(ReviewWorkflow::class);
        $review = $reviews->publish($client, $reservation->id, self::CONTENT)['review'];
        $this->travel(1)->minute();
        $withdrawn = $reviews->withdraw($client, $review->id);
        $this->assertFalse($reviews->publish($client, $reservation->id, self::CONTENT)['created']);
        $this->assertSame($withdrawn->withdrawn_at_utc, $reviews->withdraw($client, $review->id)->withdrawn_at_utc);
        $rows = $this->activity()->where('service_review_id', $review->id)->orderBy('id')->get();
        $this->assertSame(['review_published', 'review_withdrawn'], $rows->pluck('event')->all());
        $this->assertSame([$provider->id, $provider->id], $rows->pluck('user_id')->all());
        $this->assertSame([$review->created_at, $withdrawn->withdrawn_at_utc], $rows->pluck('created_at')->all());
        $this->assertSame([null, null], $rows->pluck('reservation_version')->all());
    }

    public function test_sql_guards_destinations_versions_history_and_decimal_foreign_key(): void
    {
        $reservation = $this->confirm($this->proposal());
        $notice = $this->activity()->where('service_reservation_id', $reservation->id)->where('event', 'reservation_confirmed')->sole();
        $row = (array) $notice;
        unset($row['id']);
        foreach ([['user_id' => $reservation->providerProfile->user_id], ['event' => 'reservation_rejected'], ['reservation_version' => 922],
            ['reservation_version' => '2.5'], ['created_at' => '2030-01-03 12:00:00']] as $patch) {
            $this->assertMySqlError(1644, fn () => $this->activity()->insert($patch + $row), 'activity_reservation_recipient');
        }
        $this->assertMySqlError(1644, fn () => $this->activity()->insert(['read_at' => self::FIRST_READ] + $row), 'activity_initial_unread');
        $this->assertMySqlError(1062, fn () => $this->activity()->insert($row));
        $current = $this->activity()->where('id', $notice->id);
        $this->assertMySqlError(1644, fn () => $current->update(['event' => 'reservation_cancelled', 'read_at' => self::FIRST_READ]), 'activity_immutable');
        $this->assertMySqlError(3819, fn () => $current->update(['read_at' => '2030-01-01 00:00:00']));
        $this->assertSame(1, $current->update(['read_at' => self::FIRST_READ]));
        $this->assertMySqlError(1644, fn () => $current->update(['read_at' => null]), 'activity_immutable');
        $this->assertMySqlError(1644, fn () => $current->update(['read_at' => '2030-01-10 12:00:00']), 'activity_immutable');
        $this->assertMySqlError(1644, fn () => $current->delete(), 'activity_history_required');

        $completed = $this->transition($reservation, 'completed', 'complete', $reservation->providerProfile->user);
        $this->firstReadTime();
        $review = app(ReviewWorkflow::class)->publish($completed->client, $completed->id, self::CONTENT)['review'];
        $reviewNotice = (array) $this->activity()->where('service_review_id', $review->id)->sole();
        unset($reviewNotice['id']);
        $this->assertMySqlError(1644, fn () => $this->activity()->insert(['user_id' => $completed->client_user_id] + $reviewNotice), 'activity_review_recipient');
        $this->assertMySqlError(1644, fn () => $this->activity()->insert(['event' => 'review_withdrawn'] + $reviewNotice), 'activity_review_recipient');
        $this->assertMySqlError(1062, fn () => $this->activity()->insert($reviewNotice));

        $columns = DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'activity_notifications');
        $this->assertSame('decimal(49,30)', $columns->where('COLUMN_NAME', 'reservation_version')->value('COLUMN_TYPE'));
        $foreign = DB::table('information_schema.KEY_COLUMN_USAGE')->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'activity_notifications')->where('REFERENCED_TABLE_NAME', 'reservation_events')->orderBy('ORDINAL_POSITION');
        $this->assertSame(['service_reservation_id' => 'service_reservation_id', 'reservation_version' => 'version'],
            $foreign->pluck('REFERENCED_COLUMN_NAME', 'COLUMN_NAME')->all());
    }

    public function test_activity_failure_reverts_reservation_and_event_chain(): void
    {
        $inquiry = $this->acceptedInquiry();
        $before = $this->business();
        $activity = $this->activity()->count();
        $this->withNoticeFault(fn () => $this->assertMySqlError(1062,
            fn () => DB::table('service_reservations')->insert($this->reservationRow($inquiry))));
        $this->assertEquals($before, $this->business());
        $this->assertSame($activity, $this->activity()->count());
        $this->assertFalse($inquiry->reservations()->exists());
        $id = DB::table('service_reservations')->insertGetId($this->reservationRow($inquiry));
        $reservation = ServiceReservation::findOrFail($id);
        $before = $this->business();
        $activity = $this->activity()->count();
        $this->withNoticeFault(fn () => $this->assertMySqlError(1062, fn () => $this->confirm($reservation)));
        $this->assertEquals($before, $this->business());
        $this->assertSame($activity, $this->activity()->count());
        $this->assertNotNull($this->activity()->where('service_reservation_id', $id)->where('event', 'reservation_requested')->sole());
        $this->assertSame('confirmed', $this->confirm($reservation)->status->value);
        $this->assertNotNull($this->activity()->where('service_reservation_id', $id)->where('event', 'reservation_confirmed')->sole());
    }

    public function test_activity_failure_is_not_a_review_duplicate_and_reverts_both_review_operations(): void
    {
        $reservation = $this->confirm($this->proposal());
        $reservation = $this->transition($reservation, 'completed', 'complete', $reservation->providerProfile->user);
        $this->firstReadTime();
        $workflow = app(ReviewWorkflow::class);
        $publish = fn () => $workflow->publish($reservation->client, $reservation->id, self::CONTENT);
        $activity = $this->activity()->count();
        $this->withNoticeFault(fn () => $this->assertReviewUnavailable($publish));
        $this->assertFalse($reservation->review()->exists());
        $this->assertSame($activity, $this->activity()->count());
        $review = $publish()['review'];
        $withdraw = fn () => $workflow->withdraw($reservation->client, $review->id);
        $this->withNoticeFault(fn () => $this->assertReviewUnavailable($withdraw));
        $this->assertSame('published', $review->fresh()->status->value);
        $this->assertNull($review->fresh()->withdrawn_at_utc);
        $this->assertSame(['review_published'], $this->activity()->where('service_review_id', $review->id)->pluck('event')->all());
        $this->assertSame('withdrawn', $withdraw()->status->value);
        $this->assertSame(['review_published', 'review_withdrawn'],
            $this->activity()->where('service_review_id', $review->id)->orderBy('id')->pluck('event')->all());
    }

    public static function readPaths(): array
    {
        return ['mobile activity' => ['client', 'activity'], 'mobile inquiry' => ['client', 'inquiry'], 'web activity' => ['web', 'activity']];
    }

    #[DataProvider('readPaths')]
    public function test_simultaneous_read_and_retry_use_current_marker_from_an_older_snapshot(string $mode, string $source): void
    {
        $reservation = $this->confirm($this->proposal());
        $actor = $reservation->client;
        $inbox = app(NotificationInbox::class);
        $id = $source === 'inquiry' ? $this->acceptedNotice($reservation->inquiry)->id : $this->confirmedNotice($reservation)->id;
        $worker = $this->worker($mode, $actor, ['source' => $source, 'id' => $id, 'retry' => true]);
        $before = $this->business();
        $this->firstReadTime();
        try {
            DB::transaction(function () use ($inbox, $mode, $source, $actor, $id, $worker) {
                $notice = $mode === 'web' ? $inbox->readActivity($actor, (string) $id) : $inbox->readClient($actor, $source, (string) $id);
                $this->assertSame(self::FIRST_READ, $notice->read_at);
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['error' => 409, 'safe' => true, 'initial' => null, 'snapshot' => null, 'level' => 1], $this->workerResult($worker));
            });
            fwrite($worker[1][0], "retry\n");
            $this->assertSame(['read_at' => self::FIRST_READ, 'initial' => null, 'snapshot' => null, 'level' => 1], $this->workerResult($worker));
            $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
            $this->assertSame(self::FIRST_READ, DB::table($table)->where('id', $id)->value('read_at'));
            $this->assertEquals($before, $this->business());
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_read_from_an_old_snapshot_rechecks_the_current_client_role(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $reservation = $this->confirm($this->proposal($dual));
        $id = $this->confirmedNotice($reservation)->id;
        $worker = $this->worker('client', $dual, ['source' => 'activity', 'id' => $id, 'retry' => false]);
        try {
            $role = DB::table('roles')->where('slug', RoleName::Client->value)->value('id');
            $dual->roles()->detach($role);
            fwrite($worker[1][0], "go\n");
            $this->assertSame(['error' => 404, 'initial' => null, 'snapshot' => null, 'level' => 1], $this->workerResult($worker));
            $this->assertNull($this->activity()->where('id', $id)->value('read_at'));
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_simultaneous_activity_reads_recover_from_a_real_deadlock(): void
    {
        $left = $this->confirm($this->proposal());
        $right = $this->confirm($this->proposal());
        $leftId = $this->confirmedNotice($left)->id;
        $rightId = $this->confirmedNotice($right)->id;
        $before = $this->business();
        $workers = [];
        try {
            $workers[] = $this->worker('deadlock', $left->client, ['id' => $leftId, 'other' => $rightId]);
            $workers[] = $this->worker('deadlock', $right->client, ['id' => $rightId, 'other' => $leftId]);
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("locked\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "cross\n");
            }
            $this->assertEqualsCanonicalizing([['attempts' => 1, 'diagnostics' => []], ['attempts' => 2, 'diagnostics' => [1213]]],
                array_map($this->workerResult(...), $workers));
            $this->assertSame(['2030-01-10 12:00:00', '2030-01-10 12:00:00'], $this->activity()->whereIn('id', [$leftId, $rightId])->orderBy('id')->pluck('read_at')->all());
            $this->assertEquals($before, $this->business());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_read_committed_keeps_total_and_page_in_one_snapshot_while_a_notice_arrives(): void
    {
        $reservation = $this->confirm($this->proposal());
        $client = $reservation->client;
        $provider = $this->account(RoleName::Provider);
        $pending = app(InquiryWorkflow::class)->open($client, $this->service($provider)->id, 'Aviso pendiente')['inquiry'];
        $initial = $this->acceptedNotice($reservation->inquiry)->id;
        $worker = $this->worker('append', $provider, ['inquiry' => $pending->id]);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            $interleaved = false;
            DB::listen(function (QueryExecuted $event) use ($worker, &$interleaved) {
                if ($interleaved || ! str_contains(strtolower($event->sql), 'count(*)') || ! str_contains($event->sql, 'notification_feed')) {
                    return;
                }
                $interleaved = true;
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['committed' => true], $this->workerResult($worker));
            });
            $inbox = app(NotificationInbox::class);
            $page = $inbox->clientListing($client, perPage: 1);
            $this->assertTrue($interleaved);
            $this->assertSame(2, $page->total());
            $this->assertSame('inquiry', $page->items()[0]->source_type);
            $this->assertSame($initial, $page->items()[0]->id);
            $updated = $inbox->clientListing($client, perPage: 1);
            $this->assertSame(3, $updated->total());
            $this->assertSame($this->acceptedNotice($pending)->id, $updated->items()[0]->id);
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->closeWorker($worker);
        }
    }

    public function test_mobile_scope_web_inbox_and_read_preserve_privacy_for_dual_accounts(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $own = $this->confirm($this->proposal($dual));
        $received = $this->confirm($this->proposal(provider: $dual));
        $received = $this->transition($received, 'completed', 'complete', $dual);
        $this->firstReadTime();
        $review = app(ReviewWorkflow::class)->publish($received->client, $received->id, self::CONTENT)['review'];
        app(ReviewWorkflow::class)->withdraw($received->client, $review->id);
        $forged = DB::table('inquiry_notifications')->insertGetId(['user_id' => $dual->id, 'service_inquiry_id' => $received->service_inquiry_id,
            'event' => 'accepted', 'created_at' => self::FIRST_READ]);
        $inquiryId = $this->acceptedNotice($own->inquiry)->id;
        $activity = $this->confirmedNotice($own);
        $before = $this->business();
        $headers = $this->bearer($dual);
        $baseUrl = '/api/v1/notifications';
        $this->getJson($baseUrl)->assertUnauthorized();
        $first = $this->getJson($baseUrl.'?per_page=1', $headers)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonMissingPath('links')->json('data.0');
        $this->assertSame('inquiry:'.$inquiryId, $first['id']);
        $this->getJson($baseUrl.'?page=2&per_page=1', $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'activity:'.$activity->id)->assertJsonPath('data.0.resource.id', $own->id);
        $this->getJson($baseUrl.'?page=3&per_page=1', $headers)->assertNotFound();
        $this->assertSame(['id', 'source_type', 'source_id', 'event', 'label', 'created_at', 'read_at', 'resource'], array_keys($first));
        $this->assertSame('2030-01-02T12:00:00.000000Z', $first['created_at']);
        $this->getJson($baseUrl.'/unread-count', $headers)->assertExactJson(['data' => ['unread_count' => 2]]);
        foreach ($this->activity()->where('user_id', $dual->id)->where('id', '<>', $activity->id)->pluck('id') as $hidden) {
            $this->postJson($baseUrl.'/activity/'.$hidden.'/read', [], $headers)->assertNotFound();
            $this->assertNull($this->activity()->where('id', $hidden)->value('read_at'));
        }
        $this->postJson($baseUrl.'/inquiry/'.$forged.'/read', [], $headers)->assertNotFound();
        $this->withoutVite();
        Auth::forgetGuards();
        $this->actingAs($received->client, 'web')->postJson(route('notifications.activity.read', $activity->id))->assertNotFound();
        $this->actingAs($dual, 'web')->get(route('notifications.index'))->assertOk()->assertSee('Reserva propuesta')
            ->assertSee('Reseña retirada')->assertDontSee(self::CONTENT['comment'])->assertDontSee($received->client->email);
        $this->post(route('notifications.activity.read', $activity->id))->assertStatus(303);
        $this->travel(1)->hour();
        Auth::forgetGuards();
        $this->postJson($baseUrl.'/activity/'.$activity->id.'/read', [], $headers)->assertOk()
            ->assertJsonPath('data.read_at', '2030-01-09T12:00:00.000000Z')->assertJsonMissingPath('data.user_id');
        $this->getJson($baseUrl.'/unread-count', $headers)->assertExactJson(['data' => ['unread_count' => 1]]);
        $this->assertEquals($before, $this->business());
    }

    public function test_mysql_storage_error_with_a_sqlite_busy_low_byte_is_unavailable(): void
    {
        $reservation = $this->confirm($this->proposal());
        $notice = $this->confirmedNotice($reservation);
        $headers = $this->bearer($reservation->client);
        $path = '/api/v1/notifications/activity/'.$notice->id.'/read';
        DB::unprepared("CREATE TRIGGER v815_read_fault BEFORE UPDATE ON activity_notifications FOR EACH ROW
            SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1285, MESSAGE_TEXT = 'private lab error'");
        try {
            $this->postJson($path, [], $headers)->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')->assertDontSee('private lab error');
            $this->assertNull($this->activity()->where('id', $notice->id)->value('read_at'));
        } finally {
            DB::unprepared('DROP TRIGGER v815_read_fault');
        }
        $this->postJson($path, [], $headers)->assertOk();
    }

    private function firstReadTime(): void
    {
        $this->travelTo(now('UTC')->setDate(2030, 1, 9)->setTime(12, 0, 0));
    }

    private function activity(): Builder
    {
        return DB::table('activity_notifications');
    }

    private function acceptedInquiry(?User $client = null, ?User $provider = null): ServiceInquiry
    {
        $client ??= $this->account(RoleName::Client);
        $provider ??= $this->account(RoleName::Provider);
        $workflow = app(InquiryWorkflow::class);
        $opened = $workflow->open($client, $this->service($provider)->id, 'Solicitud de prueba V8.1.5')['inquiry'];

        return $workflow->decide($provider, $opened->id, InquiryAction::Accept);
    }

    private function proposal(?User $client = null, ?User $provider = null): ServiceReservation
    {
        return ServiceReservation::findOrFail(DB::table('service_reservations')->insertGetId($this->reservationRow($this->acceptedInquiry($client, $provider))));
    }

    private function confirm(ServiceReservation $reservation): ServiceReservation
    {
        return $this->transition($reservation, 'confirmed', 'confirm', $reservation->providerProfile->user);
    }

    private function transition(ServiceReservation $reservation, string $status, string $reason, User $actor): ServiceReservation
    {
        if ($status === 'completed') {
            $this->bookingFixtureClock($reservation->ends_at_utc);
        }
        try {
            DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => $status, 'version' => $reservation->version + 1,
                'last_reason' => $reason, 'last_actor_user_id' => $actor->id,
                'updated_at' => $status === 'completed' ? $reservation->ends_at_utc : '2030-01-02 12:00:00']);
        } finally {
            $this->bookingFixtureClock(null);
        }

        return $reservation->refresh();
    }

    private function assertNotices(ServiceReservation $reservation, array $expected): void
    {
        $rows = $this->activity()->where('service_reservation_id', $reservation->id)->orderBy('reservation_version')->get();
        $this->assertSame($expected, $rows->pluck('user_id', 'event')->all());
        $this->assertSame($reservation->events()->orderBy('version')->pluck('occurred_at_utc')->all(), $rows->pluck('created_at')->all());
        $this->assertSame(range(1, count($expected)), $rows->map(fn ($row) => (int) $row->reservation_version)->all());
    }

    private function acceptedNotice(ServiceInquiry $inquiry): object
    {
        return DB::table('inquiry_notifications')->where('service_inquiry_id', $inquiry->id)->where('user_id', $inquiry->client_user_id)
            ->where('event', 'accepted')->sole();
    }

    private function confirmedNotice(ServiceReservation $reservation): object
    {
        return $this->activity()->where('service_reservation_id', $reservation->id)->where('event', 'reservation_confirmed')->sole();
    }

    private function business(): array
    {
        $tables = ['service_inquiries', 'service_reservations', 'reservation_events', 'service_reviews'];

        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables);
    }

    private function withNoticeFault(callable $operation): void
    {
        DB::unprepared('CREATE TABLE v815_notification_fault (id INT PRIMARY KEY) ENGINE=InnoDB');
        DB::table('v815_notification_fault')->insert(['id' => 1]);
        DB::unprepared('CREATE TRIGGER v815_notification_failure AFTER INSERT ON activity_notifications FOR EACH ROW INSERT INTO v815_notification_fault VALUES (1)');
        try {
            $operation();
        } finally {
            DB::unprepared('DROP TRIGGER v815_notification_failure');
            DB::unprepared('DROP TABLE v815_notification_fault');
        }
    }

    private function assertReviewUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected storage failure, not a successful replay.');
        } catch (ReviewFailure $failure) {
            $this->assertSame(503, $failure->status);
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString('v815_notification', $failure->getMessage());
        }
    }

    private function worker(string $mode, User $actor, array $details): array
    {
        return $this->startWorker('notification-worker.php', json_encode(['mode' => $mode, 'actor' => $actor->id] + $details, JSON_THROW_ON_ERROR));
    }

    private function bearer(User $actor): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$actor->createToken('mobile:V8.1.5', ['client:read'], now()->addDays(30))->plainTextToken];
    }
}
