<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Exceptions\ReviewFailure;
use App\Models\Service;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use App\Services\InquiryWorkflow;
use App\Services\PublicReviews;
use App\Services\ReviewWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class ReviewTest extends LabTestCase
{
    private array $tokens = [];

    private const CONTENT = ['rating' => 4, 'comment' => 'Texto de la reseña sintética.'];

    public function test_web_owner_can_publish_view_and_withdraw_while_the_provider_is_denied(): void
    {
        $reservation = $this->reservation();
        $this->withoutVite();
        $this->get(route('reviews.create', $reservation))->assertRedirect(route('login'));
        $this->actingAs($reservation->providerProfile->user)->postJson(route('reviews.store', $reservation), self::CONTENT)->assertForbidden();
        $this->actingAs($reservation->client)->get(route('reviews.create', $reservation))->assertOk()->assertSee('Dejar reseña');
        $published = $this->post(route('reviews.store', $reservation), self::CONTENT)->assertStatus(303);
        $review = $reservation->review()->sole();
        $published->assertRedirect(route('reviews.show', $review));
        $this->get(route('reviews.show', $review))->assertOk()->assertSee(self::CONTENT['comment']);
        $this->actingAs($reservation->providerProfile->user)->patchJson(route('reviews.withdraw', $review))->assertForbidden();
        $this->actingAs($reservation->client)->patch(route('reviews.withdraw', $review))->assertStatus(303)->assertRedirect(route('reviews.show', $review));
        $this->get(route('reviews.index'))->assertOk()->assertSee('Retirada')->assertSee(self::CONTENT['comment']);
    }

    public function test_review_api_privacy_replays_and_public_pagination(): void
    {
        $reservation = $this->reservation();
        $other = $this->account(RoleName::Client, RoleName::Provider);
        $owner = fn () => $this->bearer($reservation->client);
        $foreign = fn () => $this->bearer($other);
        $store = route('api.v1.reviews.store', $reservation);
        $this->postJson($store, self::CONTENT)->assertUnauthorized();
        $this->postJson($store, self::CONTENT, $foreign())->assertNotFound();
        $this->postJson($store, ['rating' => '4'], $owner())->assertUnprocessable();
        $this->postJson($store, ['rating' => 4, 'comment' => "texto\0privado"], $owner())->assertUnprocessable();
        $id = $this->postJson($store, self::CONTENT, $owner())->assertCreated()->assertJsonPath('data.status', 'published')->json('data.id');
        $this->postJson($store, self::CONTENT, $owner())->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($store, ['rating' => 5] + self::CONTENT, $owner())->assertConflict();
        $show = route('api.v1.reviews.show', $id);
        $withdraw = route('api.v1.reviews.withdraw', $id);
        $this->get($show, $foreign())->assertNotFound()->assertDontSee(self::CONTENT['comment']);
        $this->patchJson($withdraw, [], $foreign())->assertNotFound();
        $this->get(route('api.v1.reviews.index'), $foreign())->assertOk()->assertJsonCount(0, 'data');

        $service = route('api.v1.services.reviews.index', $reservation->service_id);
        $provider = route('api.v1.providers.reviews.index', $reservation->provider_profile_id);
        $public = ['rating' => 4, 'comment' => self::CONTENT['comment'], 'month' => 'enero de 2030',
            'service_id' => $reservation->service_id, 'service_title' => $reservation->service->title];
        foreach ([$service, $provider] as $url) {
            $this->get($url)->assertOk()->assertExactJson(['data' => [$public],
                'meta' => ['current_page' => 1, 'per_page' => 5, 'last_page' => 1, 'total' => 1, 'average_rating' => 4]])
                ->assertDontSee($reservation->client->name)->assertDontSee($reservation->client->email);
            $this->get($url.'?page=2')->assertNotFound();
        }
        $retired = $this->patch($withdraw, [], $owner())->assertOk()->assertJsonPath('data.status', 'withdrawn')->json();
        $this->travel(2)->minutes();
        $this->patch($withdraw, [], $owner())->assertOk()->assertExactJson($retired);
        $this->postJson($store, self::CONTENT, $owner())->assertOk()->assertExactJson($retired);
        $this->postJson($store, ['rating' => 4, 'comment' => 'Otro texto.'], $owner())->assertConflict();
        $this->get($show, $owner())->assertOk()->assertExactJson($retired);
        foreach ([$service, $provider] as $url) {
            $this->get($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.average_rating', null)
                ->assertDontSee(self::CONTENT['comment']);
        }
        for ($position = 1; $position <= 6; $position++) {
            $otherReservation = $this->reservation(service: $reservation->service);
            app(ReviewWorkflow::class)->publish($otherReservation->client, $otherReservation->id, ['rating' => 5, 'comment' => 'Reseña '.$position]);
        }
        $this->get($service)->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.comment', 'Reseña 6');
        $this->get($service.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.comment', 'Reseña 1')
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 6)->assertJsonPath('meta.average_rating', 5);
        $reservation->service->update(['status' => 'paused']);
        $this->get($service)->assertNotFound();
        $this->get($provider)->assertNotFound();
        $this->assertSame(1, $reservation->review()->count());
    }

    public function test_direct_insert_requires_a_completed_reservation_valid_identity_and_safe_content(): void
    {
        $pending = $this->reservation(false);
        $pendingRow = $this->row($pending);
        $this->assertMySqlError(1644, fn () => DB::table('service_reviews')->insert($pendingRow), 'review_completed_identity');
        try {
            app(ReviewWorkflow::class)->publish($pending->client, $pending->id, self::CONTENT);
            $this->fail('Expected review of a requested reservation to fail.');
        } catch (ReviewFailure $failure) {
            $this->assertSame(409, $failure->status);
        }
        $reservation = $this->reservation();
        $row = $this->row($reservation);
        foreach ([['service_reservation_id' => PHP_INT_MAX], ['client_user_id' => $pending->client_user_id],
            ['provider_profile_id' => $pending->provider_profile_id], ['service_id' => $pending->service_id],
            ['created_at' => '2030-01-01 12:00:00']] as $patch) {
            $this->assertMySqlError(1644, fn () => DB::table('service_reviews')->insert($patch + $row), 'review_completed_identity');
        }
        $this->assertMySqlError(1644, fn () => DB::table('service_reviews')->insert(['status' => 'withdrawn'] + $row), 'review_initial_state');
        foreach ([['rating' => 0], ['rating' => 6], ['rating' => '4.5'], ['comment' => ''], ['comment' => str_repeat('ó', 1001)],
            ['comment' => "texto\0privado"], ['created_at' => '2030-02-30 12:00:00']] as $patch) {
            $this->assertMySqlError(3819, fn () => DB::table('service_reviews')->insert($patch + $row));
        }
        $id = DB::table('service_reviews')->insertGetId(['comment' => str_repeat('ó', 1000)] + $row);
        $this->assertSame(1000, mb_strlen(ServiceReview::findOrFail($id)->comment));
        $this->assertMySqlError(1062, fn () => DB::table('service_reviews')->insert($row));
        $this->assertSame(['provider_profiles', 'service_reservations', 'services', 'users', 'users'], DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'service_reviews')->whereNotNull('REFERENCED_TABLE_NAME')
            ->orderBy('REFERENCED_TABLE_NAME')->pluck('REFERENCED_TABLE_NAME')->all());
        $this->assertMySqlError(1451, fn () => $reservation->client->delete());
    }

    public function test_direct_mutation_cannot_change_content_withdraw_as_foreign_or_restore_a_review(): void
    {
        $reservation = $this->reservation();
        $workflow = app(ReviewWorkflow::class);
        $review = $workflow->publish($reservation->client, $reservation->id, self::CONTENT)['review'];
        $query = DB::table('service_reviews')->where('id', $review->id);
        $withdraw = ['status' => 'withdrawn', 'withdrawn_by_user_id' => $reservation->client_user_id, 'withdrawn_at_utc' => $review->created_at];
        foreach ([['comment' => strtoupper(self::CONTENT['comment'])], ['comment' => self::CONTENT['comment'].' '], ['comment' => null],
            ['rating' => 5], ['client_user_id' => PHP_INT_MAX], ['service_id' => PHP_INT_MAX], ['provider_profile_id' => PHP_INT_MAX],
            ['service_reservation_id' => PHP_INT_MAX], ['created_at' => '2030-01-09 12:00:00']] as $patch) {
            $this->assertMySqlError(1644, fn () => $query->update($patch + $withdraw), 'review_immutable');
        }
        $this->assertMySqlError(3819, fn () => $query->update(['withdrawn_by_user_id' => $reservation->providerProfile->user_id] + $withdraw));
        $this->assertMySqlError(3819, fn () => $query->update(['withdrawn_at_utc' => '2030-01-01 12:00:00'] + $withdraw));
        $this->assertSame(1, $query->update($withdraw));
        $this->assertMySqlError(1644, fn () => $query->update(['status' => 'published', 'withdrawn_by_user_id' => null, 'withdrawn_at_utc' => null]), 'review_immutable');
        $this->assertMySqlError(1644, fn () => $query->delete(), 'review_history_required');
        $this->assertSame(self::CONTENT['comment'], $review->fresh()->comment);
    }

    public static function isolationLevels(): array
    {
        return ['repeatable read' => ['REPEATABLE READ'], 'read committed' => ['READ COMMITTED']];
    }

    #[DataProvider('isolationLevels')]
    public function test_two_publishers_reuse_one_review_without_writing_reservation_history(string $isolation): void
    {
        $reservation = $this->reservation();
        $before = $reservation->getRawOriginal();
        $events = $reservation->events()->orderBy('version')->get()->toArray();
        $workers = [];
        try {
            $workers[] = $this->worker($reservation, 'publish', $isolation);
            $workers[] = $this->worker($reservation, 'publish', $isolation);
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            $results = array_map($this->workerResult(...), $workers);
            $this->assertEqualsCanonicalizing([true, false], array_column($results, 'created'));
            $this->assertSame([null, null], array_column($results, 'initial'));
            $winner = $reservation->review()->sole();
            $this->assertSame([$winner->id, $winner->id], array_column($results, 'id'));
            $this->assertSame(['published', 'published'], array_column($results, 'status'));
            $this->assertSame($before, $reservation->fresh()->getRawOriginal());
            $this->assertSame($events, $reservation->events()->orderBy('version')->get()->toArray());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public static function replays(): array
    {
        return ['withdraw older snapshot' => ['withdraw', 'REPEATABLE READ', 'published'],
            'publish older snapshot' => ['publish', 'REPEATABLE READ', 'published'],
            'withdraw read committed' => ['withdraw', 'READ COMMITTED', 'withdrawn']];
    }

    #[DataProvider('replays')]
    public function test_replay_after_concurrent_withdrawal_returns_the_original_withdrawal(string $action, string $isolation, string $snapshot): void
    {
        $reservation = $this->reservation();
        $workflow = app(ReviewWorkflow::class);
        $review = $workflow->publish($reservation->client, $reservation->id, self::CONTENT)['review'];
        $worker = $this->worker($reservation, $action, $isolation, $review->id, true);
        try {
            $withdrawn = DB::transaction(function () use ($workflow, $reservation, $review, $worker) {
                $current = $workflow->withdraw($reservation->client, $review->id);
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['error' => 409, 'sanitized' => true, 'initial' => 'published', 'snapshot' => 'published', 'level' => 1],
                    $this->workerResult($worker));

                return $current;
            });
            fwrite($worker[1][0], "retry\n");
            $expected = $action === 'publish' ? ['created' => false] : [];
            $this->assertSame($expected + ['id' => $review->id, 'status' => 'withdrawn', 'withdrawn_at' => $withdrawn->withdrawn_at_utc,
                'initial' => 'published', 'snapshot' => $snapshot, 'level' => 1], $this->workerResult($worker));
            $this->assertSame($withdrawn->getAttributes(), $review->fresh()->getAttributes());
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_read_committed_uses_one_public_snapshot_when_withdrawal_commits(): void
    {
        $reservation = $this->reservation();
        $review = app(ReviewWorkflow::class)->publish($reservation->client, $reservation->id, self::CONTENT)['review'];
        $worker = $this->worker($reservation, 'withdraw', 'READ COMMITTED', $review->id);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            $interleaved = false;
            DB::listen(function (QueryExecuted $event) use ($worker, &$interleaved) {
                if ($interleaved || ! str_contains($event->sql, 'AVG(rating)')) {
                    return;
                }
                $interleaved = true;
                fwrite($worker[1][0], "go\n");
                $this->assertSame('withdrawn', $this->workerResult($worker)['status']);
            });
            $public = app(PublicReviews::class);
            $before = $public->forProvider($reservation->provider_profile_id);
            $this->assertTrue($interleaved);
            $this->assertSame(1, $before['count']);
            $this->assertSame(4.0, $before['average']);
            $this->assertSame(self::CONTENT['comment'], $before['reviews']->items()[0]['comment']);
            $after = $public->forProvider($reservation->provider_profile_id);
            $this->assertSame(0, $after['count']);
            $this->assertNull($after['average']);
            $this->assertSame([], $after['reviews']->items());
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->closeWorker($worker);
        }
    }

    public function test_rollback_and_mysql_error_classification_do_not_leak_storage_details(): void
    {
        $reservation = $this->reservation();
        $before = $reservation->getRawOriginal();
        $events = $reservation->events()->orderBy('version')->get()->toArray();
        $publish = fn () => app(ReviewWorkflow::class)->publish($reservation->client, $reservation->id, self::CONTENT);
        try {
            DB::transaction(function () use ($publish) {
                $this->assertTrue($publish()['created']);
                throw new RuntimeException('rollback review');
            });
            $this->fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback review', $exception->getMessage());
        }
        $this->assertFalse($reservation->review()->exists());
        foreach ([['HY000', 1285, 'synthetic storage failure', 503], ['45000', 1644, 'synthetic trigger failure', 503],
            ['45000', 1644, 'review_completed_identity', 409]] as [$state, $code, $message, $status]) {
            DB::unprepared("CREATE TRIGGER v814_fault BEFORE INSERT ON service_reviews FOR EACH ROW
                SIGNAL SQLSTATE '{$state}' SET MYSQL_ERRNO = {$code}, MESSAGE_TEXT = '{$message}'");
            try {
                $publish();
                $this->fail('Expected review failure.');
            } catch (ReviewFailure $failure) {
                $this->assertSame($status, $failure->status);
                $this->assertNull($failure->getPrevious());
                $this->assertStringNotContainsString($message, $failure->getMessage());
                $this->assertStringNotContainsString('SQL', $failure->getMessage());
            } finally {
                DB::unprepared('DROP TRIGGER v814_fault');
            }
            $this->assertFalse($reservation->review()->exists());
        }
        $this->assertTrue($publish()['created']);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($events, $reservation->events()->orderBy('version')->get()->toArray());
    }

    private function reservation(bool $completed = true, ?Service $service = null): ServiceReservation
    {
        $provider = $service?->providerProfile->user ?? $this->account(RoleName::Provider);
        $service ??= $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($this->account(RoleName::Client), $service->id, 'Reseña sintética V8.1.4')['inquiry'];
        $inquiry = $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        $this->travelTo(now('UTC')->setDate(2030, 1, 8)->setTime(12, 0, 0));

        return $completed ? $this->completedReservation($inquiry, $provider)
            : ServiceReservation::findOrFail(DB::table('service_reservations')->insertGetId($this->reservationRow($inquiry)));
    }

    private function bearer(User $user): array
    {
        Auth::forgetGuards();

        $this->tokens[$user->id] ??= $user->createToken('mobile:V8.1.4', ['client:read'], now()->addDays(30))->plainTextToken;

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id], 'Accept' => 'application/json'];
    }

    private function row(ServiceReservation $reservation): array
    {
        return ['service_reservation_id' => $reservation->id, 'service_id' => $reservation->service_id,
            'provider_profile_id' => $reservation->provider_profile_id, 'client_user_id' => $reservation->client_user_id,
            'created_at' => now('UTC')->format('Y-m-d H:i:s')] + self::CONTENT;
    }

    private function worker(ServiceReservation $reservation, string $action, string $isolation, ?int $review = null, bool $retry = false): array
    {
        return $this->startWorker('review-worker.php', json_encode(['actor' => $reservation->client_user_id, 'reservation' => $reservation->id,
            'action' => $action, 'isolation' => $isolation, 'review' => $review, 'retry' => $retry, 'content' => self::CONTENT], JSON_THROW_ON_ERROR));
    }
}
