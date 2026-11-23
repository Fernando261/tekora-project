<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
final class MobileReviewsTest extends TestCase
{
    use MobileOperationsFixtures;
    use ReviewFixtures;

    public function test_completed_reservation_publishes_once_and_replays_without_editing_the_review(): void
    {
        [$client, , $inquiry, $reservation] = $this->reviewContext();
        $before = $reservation->getRawOriginal();
        $events = $reservation->events()->count();
        $url = route('api.v1.reviews.store', $reservation);
        $this->get(route('api.v1.inquiries.journey', $inquiry), $this->bearer($client))->assertOk()
            ->assertJsonPath('data.next_action.code', 'write_review')->assertJsonPath('data.next_action.mobile_execution_available', true);

        $input = ['rating' => 5, 'comment' => " Servicio realizado.\r\nGracias por la visita. "];
        $created = $this->postJson($url, $input, $this->bearer($client))->assertCreated();
        $id = $created->json('data.id');
        $created->assertExactJson(['data' => ['id' => $id, 'reservation_id' => $reservation->id, 'service_id' => $reservation->service_id,
            'service_title' => $reservation->service->title, 'rating' => 5, 'comment' => "Servicio realizado.\nGracias por la visita.",
            'status' => 'published', 'created_at' => now('UTC')->startOfSecond()->toISOString(), 'withdrawn_at' => null]]);
        $this->postJson($url, ['rating' => 5, 'comment' => "Servicio realizado.\nGracias por la visita."], $this->bearer($client))
            ->assertOk()->assertExactJson($created->json());
        foreach ([['rating' => 1] + $input, ['comment' => 'Otro comentario.'] + $input] as $changed) {
            $this->postJson($url, $changed, $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
        }
        $this->patchJson(route('api.v1.reviews.show', $id), ['rating' => 2], $this->bearer($client))->assertStatus(405);
        $this->get(route('api.v1.reviews.show', $id), $this->bearer($client))->assertOk()->assertExactJson($created->json());
        $this->get(route('api.v1.inquiries.journey', $inquiry), $this->bearer($client))->assertOk()
            ->assertJsonPath('data.next_action.code', 'view_review')->assertJsonPath('data.next_action.reference.id', $id)
            ->assertJsonPath('data.next_action.mobile_execution_available', true);
        $this->assertDatabaseCount('service_reviews', 1);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($events, $reservation->events()->count());
    }

    public function test_pending_and_past_confirmed_reservations_cannot_be_reviewed(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $url = route('api.v1.reviews.store', $reservation);
        $this->postJson($url, ['rating' => 5], $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC')->addHour());
        $this->postJson($url, ['rating' => 5], $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->assertDatabaseCount('service_reviews', 0);
    }

    public function test_private_routes_require_a_mobile_client_and_foreign_records_match_missing_records(): void
    {
        [$client, $dualProvider, , $reservation] = $this->reviewContext();
        $review = $this->publishReview($reservation, 5, 'El texto privado de otra cuenta.');
        $index = route('api.v1.reviews.index');
        $show = route('api.v1.reviews.show', $review);
        $store = route('api.v1.reviews.store', $reservation);
        $withdraw = route('api.v1.reviews.withdraw', $review);
        foreach ([['GET', $index], ['GET', $show], ['POST', $store], ['PATCH', $withdraw]] as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
        $providerOnly = $this->account(RoleName::Provider);
        $this->get($index, $this->bearer($providerOnly))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson($store, ['rating' => 3], $this->bearer($providerOnly))->assertForbidden();
        $this->get($index, $this->bearer($dualProvider))->assertOk()->assertJsonCount(0, 'data');
        foreach ([['GET', 'api.v1.reviews.show', $review->id], ['POST', 'api.v1.reviews.store', $reservation->id],
            ['PATCH', 'api.v1.reviews.withdraw', $review->id]] as [$method, $route, $id]) {
            $foreign = $this->json($method, route($route, $id), ['client_user_id' => $client->id], $this->bearer($dualProvider))
                ->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertDontSee($review->comment);
            $this->json($method, route($route, 999999), [], $this->bearer($dualProvider))->assertNotFound()->assertExactJson($foreign->json());
        }
        $this->assertDatabaseHas('service_reviews', ['id' => $review->id, 'status' => 'published', 'rating' => 5]);
    }

    public function test_dual_account_lists_only_its_own_reviews_as_client(): void
    {
        [, $dual, , $reservation] = $this->reviewContext();
        $incoming = $this->publishReview($reservation);
        $otherProvider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($dual, $otherProvider, InquiryStatus::Accepted);
        $this->publishSchedule($otherProvider, 'America/Mexico_City');
        $outgoing = $this->publishReview($this->completeForReview($inquiry));
        $this->get(route('api.v1.reviews.index'), $this->bearer($dual))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $outgoing->id)->assertJsonPath('meta.total', 1);
        $this->get(route('api.v1.reviews.show', $incoming), $this->bearer($dual))->assertNotFound();
        $this->get(route('api.v1.reviews.show', $outgoing), $this->bearer($dual))->assertOk();
    }

    public function test_public_service_and_provider_use_anonymous_projection_and_weighted_totals(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $first = $this->publishReview($reservation, 5, '<b>Mensaje de prueba</b>');
        $this->publishReview($this->completeForReview($inquiry), 5, null);
        $secondInquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $this->publishReview($this->completeForReview($secondInquiry), 1, 'Otro servicio.');
        $publicService = route('api.v1.services.reviews.index', $reservation->service_id);
        $publicProvider = route('api.v1.providers.reviews.index', $reservation->provider_profile_id);
        $body = ['rating' => 5, 'comment' => $first->comment, 'month' => 'enero de 2030',
            'service_id' => $reservation->service_id, 'service_title' => $reservation->service->title];
        $this->get($publicService)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertExactJson(['data' => [['comment' => null] + $body, $body],
                'meta' => ['current_page' => 1, 'per_page' => 5, 'last_page' => 1, 'total' => 2, 'average_rating' => 5]])
            ->assertDontSee($client->name)->assertDontSee($client->email);
        $this->get($publicProvider)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.average_rating', 3.7)
            ->assertJsonPath('data.0.service_id', $secondInquiry->service_id)->assertDontSee($client->email);
        $secondInquiry->service->update(['status' => 'paused']);
        $this->get(route('api.v1.services.reviews.index', $secondInquiry->service_id))->assertNotFound();
        $this->get($publicProvider)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.average_rating', 5);
        $inquiry->service->category->update(['is_active' => false]);
        $this->get($publicService)->assertNotFound();
        $this->get($publicProvider)->assertNotFound();
        $this->assertDatabaseCount('service_reviews', 3);
    }

    public function test_withdrawal_replays_and_removes_public_contribution_but_preserves_private_history(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        $review = $this->publishReview($reservation, 4, 'Conserva este registro privado.');
        $publicUrls = [route('api.v1.services.reviews.index', $reservation->service_id), route('api.v1.providers.reviews.index', $reservation->provider_profile_id)];
        foreach ($publicUrls as $url) {
            $this->get($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.average_rating', 4);
        }
        $withdraw = route('api.v1.reviews.withdraw', $review);
        $result = $this->patch($withdraw, [], $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertNotNull($result->json('data.withdrawn_at'));
        $this->travel(1)->minutes();
        $this->patch($withdraw, [], $this->bearer($client))->assertOk()->assertExactJson($result->json());
        $this->postJson(route('api.v1.reviews.store', $reservation), ['rating' => 4, 'comment' => $review->comment], $this->bearer($client))
            ->assertOk()->assertExactJson($result->json());
        $this->postJson(route('api.v1.reviews.store', $reservation), ['rating' => 5], $this->bearer($client))->assertConflict();
        foreach ($publicUrls as $url) {
            $this->get($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.average_rating', null)
                ->assertDontSee($review->comment);
        }
        $this->get(route('api.v1.reviews.index'), $this->bearer($client))->assertOk()->assertJsonPath('data.0', $result->json('data'));
        $this->get(route('api.v1.reviews.show', $review), $this->bearer($client))->assertOk()->assertExactJson($result->json());
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_inconsistent_legacy_data_cannot_contribute_to_public_results(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $pending = $this->proposeReservation($inquiry);
        // Simulate a pre-existing invalid record only in this test's in-memory schema.
        DB::unprepared('DROP TRIGGER reviews_completed_identity_insert');
        DB::table('service_reviews')->insert(['service_reservation_id' => $pending->id, 'service_id' => $inquiry->service_id,
            'provider_profile_id' => $provider->providerProfile->id, 'client_user_id' => $client->id,
            'rating' => 5, 'comment' => 'Dato inconsistente.', 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
        foreach ([route('api.v1.services.reviews.index', $inquiry->service_id), route('api.v1.providers.reviews.index', $provider->providerProfile)] as $url) {
            $this->get($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.average_rating', null);
        }
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_public_and_private_pagination_reject_unknown_fields_and_out_of_range_pages(): void
    {
        [$client, , $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation, 5, 'Primera reseña.');
        for ($index = 1; $index <= 12; $index++) {
            $this->publishReview($this->completeForReview($inquiry), 4, 'Reseña '.$index);
        }
        $public = route('api.v1.services.reviews.index', $inquiry->service_id);
        $private = route('api.v1.reviews.index');
        $this->get($public.'?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.comment', 'Reseña 7')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 13);
        $this->get($public.'?page=4')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get($private.'?page=2', $this->bearer($client))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.comment', 'Primera reseña.')->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.total', 13);
        $this->get($private.'?page=3', $this->bearer($client))->assertNotFound();
        foreach (['page=0', 'page[]=1', 'page=10001', 'client_user_id=1'] as $query) {
            $this->get($public.'?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
            $this->get($private.'?'.$query, $this->bearer($client))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_invalid_writes_reject_noninteger_ratings_bad_comments_and_identity_fields(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        $url = route('api.v1.reviews.store', $reservation);
        foreach ([['rating' => '4'], ['rating' => true], ['rating' => 5.5], ['rating' => 6], [],
            ['rating' => 5, 'comment' => ['no']], ['rating' => 5, 'comment' => str_repeat('x', 1001)],
            ['rating' => 5, 'comment' => "texto\0privado"], ['rating' => 5, 'status' => 'withdrawn', 'client_user_id' => 1]] as $input) {
            $this->postJson($url, $input, $this->bearer($client))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->post($url, ['rating' => 5], $this->bearer($client))->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson($url.'?rating=5', [], $this->bearer($client))->assertUnprocessable();
        $this->assertDatabaseCount('service_reviews', 0);
        $review = $this->postJson($url, ['rating' => 5, 'comment' => str_repeat('á', 1000)], $this->bearer($client))->assertCreated();
        $withdraw = route('api.v1.reviews.withdraw', $review->json('data.id'));
        $this->patchJson($withdraw, ['rating' => 1], $this->bearer($client))->assertUnprocessable();
        $this->call('PATCH', $withdraw, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $this->bearer($client)['Authorization']], '{')
            ->assertUnprocessable();
        $this->assertDatabaseHas('service_reviews', ['id' => $review->json('data.id'), 'rating' => 5, 'status' => 'published']);
    }

    public function test_review_write_limit_is_shared_between_publish_and_withdraw(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        $url = route('api.v1.reviews.store', $reservation);
        $id = $this->postJson($url, ['rating' => 5], $this->bearer($client))->assertCreated()->json('data.id');
        for ($index = 1; $index < 10; $index++) {
            $this->postJson($url, ['rating' => 5], $this->bearer($client))->assertOk();
        }
        $withdraw = route('api.v1.reviews.withdraw', $id);
        $this->patch($withdraw, [], $this->bearer($client))->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
        $this->travel(61)->seconds();
        $this->patch($withdraw, [], $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_storage_errors_return_the_safe_envelope_for_public_and_private_operations(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        config(['app.debug' => true]);
        DB::statement('ALTER TABLE service_reviews RENAME TO reviews_synthetic_unavailable');
        try {
            foreach ([route('api.v1.services.reviews.index', $reservation->service_id), route('api.v1.reviews.index')] as $url) {
                $this->get($url, $this->bearer($client))->assertServiceUnavailable()->assertJsonPath('error.code', 'temporarily_unavailable')
                    ->assertDontSee('SQLSTATE')->assertDontSee('reviews_synthetic_unavailable');
            }
            $this->postJson(route('api.v1.reviews.store', $reservation), ['rating' => 4, 'comment' => 'Dato privado.'], $this->bearer($client))
                ->assertServiceUnavailable()->assertJsonPath('error.code', 'temporarily_unavailable')->assertDontSee('Dato privado.');
        } finally {
            DB::statement('ALTER TABLE reviews_synthetic_unavailable RENAME TO service_reviews');
        }
        $this->assertDatabaseCount('service_reviews', 0);
    }

    public function test_overlapping_writers_recover_the_original_review_and_withdrawal(): void
    {
        $uri = 'file:tekora-m29-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['m29_a', 'm29_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('m29_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'm29_a', '--force' => true]));
        foreach (['m29_a', 'm29_b'] as $name) {
            $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        }
        config(['sanctum.last_used_at' => false]);
        [$client, , , $reservation] = $this->reviewContext();
        $this->bearer($client);
        $payload = ['rating' => 5, 'comment' => 'Envío original.'];
        $writer = DB::connection('m29_a');
        $writer->beginTransaction();
        try {
            $first = $this->publishReview($reservation, $payload['rating'], $payload['comment']);
            DB::setDefaultConnection('m29_b');
            $this->postJson(route('api.v1.reviews.store', $reservation), $payload, $this->bearer($client))
                ->assertConflict()->assertJsonPath('error.code', 'conflict')->assertDontSee($payload['comment']);
        } finally {
            $writer->commit();
        }
        $this->postJson(route('api.v1.reviews.store', $reservation), $payload, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $first->id);

        DB::setDefaultConnection('m29_a');
        $writer->beginTransaction();
        $withdraw = route('api.v1.reviews.withdraw', $first);
        try {
            app(ReviewWorkflow::class)->withdraw($client, $first->id);
            DB::setDefaultConnection('m29_b');
            $this->patch($withdraw, [], $this->bearer($client))->assertServiceUnavailable()->assertDontSee('SQLSTATE');
        } finally {
            $writer->commit();
        }
        $this->patch($withdraw, [], $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.id', $first->id)->assertJsonPath('data.comment', $payload['comment']);
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_openapi_documents_only_the_approved_additional_review_operations(): void
    {
        $this->assertCurrentApiInventory();
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($this->m29ApiInventory() as $route) {
            $operation = $spec['paths'][substr($route['uri'], strlen('api/v1'))][strtolower($route['methods'][0])];
            $this->assertSame(in_array('auth:sanctum', $route['middleware'], true) ? [['bearerAuth' => []]] : [], $operation['security']);
            foreach (['404', '422', '429', '503'] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
        }
        $this->assertSame(['rating', 'comment', 'month', 'service_id', 'service_title'], $spec['components']['schemas']['M29PublicReview']['required']);
    }
}
