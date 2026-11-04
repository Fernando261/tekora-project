<?php

namespace Tests\Feature;

use App\Exceptions\ReviewFailure;
use App\Models\ServiceReview;
use App\Models\User;
use App\Services\PublicReviews;
use App\Services\ReviewWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewConcurrencyTest extends TestCase
{
    use ReviewFixtures;

    private function sharedMemory(): array
    {
        $uri = 'file:tekora-reviews-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['review_a', 'review_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('review_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'review_a', '--force' => true]));
        $this->assertNotSame(DB::connection('review_a')->getPdo(), DB::connection('review_b')->getPdo());
        foreach (['review_a', 'review_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);

        return $this->reviewContext();
    }

    public function test_overlapping_insertions_keep_one_review_and_exact_retry_recognizes_the_winner(): void
    {
        [$client, , , $reservation] = $this->sharedMemory();
        DB::setDefaultConnection('review_b'); $clientB = User::findOrFail($client->id);
        DB::setDefaultConnection('review_a'); DB::connection('review_a')->beginTransaction();
        $result = app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 4, 'comment' => 'Mismo envío.']);
        DB::setDefaultConnection('review_b');
        try { app(ReviewWorkflow::class)->publish($clientB, $reservation->id, ['rating' => 4, 'comment' => 'Mismo envío.']); $this->fail('La segunda petición está solapada con una escritura sin commit.'); }
        catch (ReviewFailure $exception) { $this->assertSame(409, $exception->status); $this->assertNull($exception->getPrevious()); $this->assertStringNotContainsString('SQL', $exception->getMessage()); }
        finally { DB::connection('review_a')->commit(); }
        $retry = app(ReviewWorkflow::class)->publish($clientB, $reservation->id, ['rating' => 4, 'comment' => 'Mismo envío.']);
        $this->assertFalse($retry['created']); $this->assertSame($result['review']->id, $retry['review']->id); $this->assertDatabaseCount('service_reviews', 1);
        $this->actingAs($clientB)->postJson(route('reviews.store', $reservation), ['rating' => 1])->assertStatus(409)->assertDontSee('SQLSTATE');
        $this->assertSame(4, ServiceReview::sole()->rating); $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_unique_constraint_blocks_direct_insert_on_the_second_connection(): void
    {
        [, , , $reservation] = $this->sharedMemory(); $review = $this->publishReview($reservation);
        $row = $review->getAttributes(); unset($row['id']);
        DB::setDefaultConnection('review_b');
        try { DB::table('service_reviews')->insert($row); $this->fail('El SELECT del servicio no sustituye a UNIQUE.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reviews', 1); }
        $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
    }

    public function test_concurrent_withdrawals_preserve_the_first_timestamp_and_the_original_content(): void
    {
        [$client, , , $reservation] = $this->sharedMemory(); $review = $this->publishReview($reservation);
        DB::setDefaultConnection('review_b'); $clientB = User::findOrFail($client->id);
        DB::setDefaultConnection('review_a'); DB::connection('review_a')->beginTransaction();
        $withdrawn = app(ReviewWorkflow::class)->withdraw($client, $review->id);
        DB::setDefaultConnection('review_b');
        try { app(ReviewWorkflow::class)->withdraw($clientB, $review->id); $this->fail('El retiro concurrente debe esperar o fallar controladamente.'); }
        catch (ReviewFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('review_a')->commit(); }
        $this->travel(1)->hours(); $retry = app(ReviewWorkflow::class)->withdraw($clientB, $review->id);
        $this->assertSame($withdrawn->withdrawn_at_utc, $retry->withdrawn_at_utc); $this->assertSame($review->rating, $retry->rating); $this->assertSame($review->comment, $retry->comment);
    }

    public function test_public_snapshot_cannot_mix_pre_withdrawal_average_with_post_withdrawal_comments(): void
    {
        [$client, $provider, , $reservation] = $this->sharedMemory(); $review = $this->publishReview($reservation, 5, 'Todavía publicada.'); $profileId = $provider->providerProfile->id;
        DB::setDefaultConnection('review_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('review_a');
        $attempted = false; $blocked = false;
        DB::listen(function ($event) use (&$attempted, &$blocked, $clientB, $review) {
            if ($attempted || $event->connectionName !== 'review_a' || ! str_contains($event->sql, 'AVG(rating)')) return;
            $attempted = true; DB::setDefaultConnection('review_b');
            try { app(ReviewWorkflow::class)->withdraw($clientB, $review->id); }
            catch (ReviewFailure $exception) { $blocked = $exception->status === 409; }
            finally { DB::setDefaultConnection('review_a'); }
        });
        $snapshot = app(PublicReviews::class)->forProvider($profileId);
        $this->assertTrue($attempted); $this->assertTrue($blocked); $this->assertSame(5.0, $snapshot['average']);
        $this->assertSame(1, $snapshot['count']); $this->assertSame('Todavía publicada.', $snapshot['reviews']->items()[0]['comment']);
        DB::setDefaultConnection('review_b'); app(ReviewWorkflow::class)->withdraw($clientB, $review->id);
        $after = app(PublicReviews::class)->forProvider($profileId); $this->assertSame(0, $after['count']); $this->assertNull($after['average']); $this->assertCount(0, $after['reviews']);
    }

    public function test_rollback_and_failed_insert_do_not_change_reviews_or_completed_reservation(): void
    {
        [$client, , , $reservation] = $this->reviewContext(); $before = $reservation->getAttributes();
        DB::beginTransaction(); $this->publishReview($reservation); DB::rollBack();
        $this->assertDatabaseCount('service_reviews', 0); $this->assertSame($before, $reservation->fresh()->getAttributes());
        DB::unprepared("CREATE TRIGGER review_forced_failure BEFORE INSERT ON service_reviews BEGIN SELECT RAISE(ABORT, 'private review failure'); END");
        config(['app.debug' => true]);
        $this->actingAs($client)->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(409)->assertDontSee('SQLSTATE')->assertDontSee('private review failure');
        $this->assertDatabaseCount('service_reviews', 0); $this->assertSame($before, $reservation->fresh()->getAttributes());
    }

    public function test_read_and_write_failures_are_controlled_even_during_blade_render_with_debug_enabled(): void
    {
        [$client, , , $reservation] = $this->sharedMemory();
        DB::setDefaultConnection('review_b'); $clientB = User::findOrFail($client->id);
        DB::setDefaultConnection('review_a'); DB::connection('review_a')->beginTransaction(); $review = $this->publishReview($reservation);
        DB::setDefaultConnection('review_b'); config(['app.debug' => true]);
        try {
            $this->actingAs($clientB)->get(route('reviews.index'))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('service_reviews');
            $this->get(route('catalog.show', $reservation->service_id))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('service_reviews');
            $this->patchJson(route('reviews.withdraw', $review))->assertStatus(409)->assertDontSee('SQLSTATE');
        } finally { DB::connection('review_a')->commit(); }
        DB::statement('ALTER TABLE service_reviews RENAME TO reviews_temporarily_missing');
        try {
            $this->getJson(route('reviews.index'))->assertStatus(503)->assertDontSee('SQLSTATE');
            $this->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(503)->assertDontSee('SQLSTATE');
            $this->get(route('catalog.show', $reservation->service_id))->assertStatus(503)->assertDontSee('SQLSTATE');
        } finally { DB::statement('ALTER TABLE reviews_temporarily_missing RENAME TO service_reviews'); }
    }
}
