<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\ReviewVisibility;
use App\Enums\RoleName;
use App\Exceptions\ReviewFailure;
use App\Models\ServiceReview;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewManagementTest extends TestCase
{
    use ReviewFixtures;

    public function test_completed_reservation_allows_review_even_while_its_inquiry_remains_accepted(): void
    {
        [$client, , $inquiry, $reservation] = $this->reviewContext();
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->actingAs($client)->get(route('reservations.show', $reservation))->assertOk()->assertSee('Dejar reseña');
        $this->get(route('reviews.create', $reservation))->assertOk()->assertSee('no podrás editarlos')->assertSee('name="rating"', false)->assertSee('maxlength="1000"', false);
        $this->post(route('reviews.store', $reservation), ['rating' => '4', 'comment' => 'Buena atención.'])->assertStatus(303);
        $review = ServiceReview::sole();
        $this->assertSame([$reservation->id, $reservation->service_id, $reservation->provider_profile_id, $client->id],
            [$review->service_reservation_id, $review->service_id, $review->provider_profile_id, $review->client_user_id]);
        $this->assertSame(4, $review->rating); $this->assertSame(ReviewVisibility::Published, $review->status);
        $this->get(route('reviews.show', $review))->assertOk()->assertSee('Buena atención.')->assertSee('Retirar reseña');
        $this->get(route('reviews.index'))->assertOk()->assertSee('Mis reseñas')->assertSee('Buena atención.');
        $this->get(route('reservations.show', $reservation))->assertOk()->assertDontSee('Dejar reseña')->assertSee('Consultar mi reseña');
        $this->get(route('reviews.create', $reservation))->assertRedirect(route('reviews.show', $review));
    }

    public function test_requested_confirmed_rejected_and_cancelled_reservations_are_not_eligible(): void
    {
        [$client, $provider] = $this->reviewContext();
        foreach (['requested', 'confirmed', 'rejected', 'cancelled'] as $offset => $state) {
            $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            $reservation = $this->proposeReservation($inquiry, ['date' => sprintf('2030-01-%02d', 8 + $offset)]);
            if ($state !== 'requested') app(ReservationWorkflow::class)->decide($state === 'cancelled' ? $client : $provider, $reservation->id,
                ['confirmed' => 'confirm', 'rejected' => 'reject', 'cancelled' => 'cancel'][$state], ['version' => 1]);
            $this->actingAs($client)->get(route('reviews.create', $reservation))->assertStatus(409);
            $this->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(409);
            $this->get(route('reservations.show', $reservation))->assertOk()->assertDontSee('Dejar reseña');
        }
        $this->assertDatabaseCount('service_reviews', 0);
    }

    public function test_third_parties_provider_and_unrelated_admin_cannot_create_read_or_withdraw(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation);
        $this->get(route('reviews.index'))->assertRedirect('/login');
        foreach ([$provider, $this->account(RoleName::Client, RoleName::Provider), $this->account(RoleName::Admin)] as $actor) {
            $this->actingAs($actor)->get(route('reviews.create', $reservation))->assertForbidden();
            $this->postJson(route('reviews.store', $reservation), ['rating' => 1])->assertForbidden();
            $this->get(route('reviews.show', $review))->assertForbidden();
            $this->patchJson(route('reviews.withdraw', $review))->assertForbidden();
        }
        $this->actingAs($client)->get(route('reviews.show', 999999))->assertNotFound();
        $this->get(route('reviews.create', 999999))->assertNotFound();
        $this->assertSame(ReviewVisibility::Published, $review->fresh()->status);
    }

    public function test_only_current_client_permissions_allow_private_access_and_provider_cannot_review_itself(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation);
        $fakeSelf = clone $reservation; $fakeSelf->client_user_id = $provider->id;
        $this->assertFalse($provider->can('create', [ServiceReview::class, $fakeSelf]));
        $client->roles()->detach();
        $this->actingAs($client)->get(route('reviews.index'))->assertForbidden();
        $this->get(route('reviews.show', $review))->assertForbidden();
        $this->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertForbidden();
        $this->patchJson(route('reviews.withdraw', $review))->assertForbidden();
        $this->assertSame(ReviewVisibility::Published, $review->fresh()->status);
    }

    public function test_invalid_stars_and_comments_are_rejected_without_any_write(): void
    {
        [$client, , , $reservation] = $this->reviewContext(); $workflow = app(ReviewWorkflow::class);
        foreach ([0, 6, -1, 4.5, 4.0, true, false, [], null, '', '05', '4e0', '4.0'] as $rating) {
            try { $workflow->publish($client, $reservation->id, compact('rating')); $this->fail('La calificación debe ser un entero 1..5.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('rating', $exception->errors()); }
        }
        foreach ([str_repeat('á', 1001), ['array'], false, "\0texto\0"] as $comment) {
            try { $workflow->publish($client, $reservation->id, ['rating' => 5, 'comment' => $comment]); $this->fail('Comentario no válido.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('comment', $exception->errors()); }
        }
        $this->actingAs($client)->postJson(route('reviews.store', $reservation), ['rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson(route('reviews.store', $reservation), ['rating' => 5, 'comment' => str_repeat('x', 1001)])->assertUnprocessable();
        $this->assertDatabaseCount('service_reviews', 0);
        $this->post(route('reviews.store', $reservation), ['rating' => 1, 'comment' => str_repeat('á', 1000)])->assertStatus(303);
        $this->assertSame(1000, mb_strlen(ServiceReview::sole()->comment));
    }

    public function test_additional_fields_and_withdrawal_payload_cannot_change_identity_or_verification(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        foreach (['client_user_id', 'service_id', 'provider_profile_id', 'service_reservation_id', 'reservation_id', 'status', 'verified', 'created_at', 'withdrawn_at_utc', 'rating_average'] as $field) {
            try { app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 5, $field => null]); $this->fail('Incluso un campo interno vacío debe rechazarse.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('form', $exception->errors()); }
        }
        $this->actingAs($client)->postJson(route('reviews.store', $reservation), ['rating' => 5, 'verified' => true])->assertUnprocessable();
        $review = $this->publishReview($reservation);
        $this->patchJson(route('reviews.withdraw', $review), ['status' => 'published'])->assertUnprocessable();
        $this->assertSame(ReviewVisibility::Published, $review->fresh()->status);
    }

    public function test_identical_retries_are_idempotent_and_different_content_cannot_overwrite(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        $this->actingAs($client)->post(route('reviews.store', $reservation), ['rating' => 5, 'comment' => "  Primera línea\r\nSegunda línea  "])->assertStatus(303);
        $review = ServiceReview::sole(); $this->assertSame("Primera línea\nSegunda línea", $review->comment);
        $this->post(route('reviews.store', $reservation), ['rating' => '5', 'comment' => "Primera línea\nSegunda línea"])->assertRedirect(route('reviews.show', $review));
        foreach ([['rating' => 4, 'comment' => $review->comment], ['rating' => 5, 'comment' => 'Texto distinto']] as $data) $this->postJson(route('reviews.store', $reservation), $data)->assertStatus(409);
        $this->assertDatabaseCount('service_reviews', 1); $this->assertSame(5, $review->fresh()->rating);
    }

    public function test_empty_comment_is_optional_and_blank_retries_are_equivalent(): void
    {
        [$client, , , $reservation] = $this->reviewContext();
        $result = app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 3]);
        $this->assertNull($result['review']->comment); $this->assertTrue($result['created']);
        $this->assertFalse(app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => '3', 'comment' => '   '])['created']);
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_withdrawal_is_irreversible_idempotent_and_never_allows_a_replacement(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 2, 'Histórico privado.');
        $this->actingAs($client)->patch(route('reviews.withdraw', $review))->assertStatus(303);
        $review = $review->fresh(); $before = $review->getAttributes();
        $this->assertSame(ReviewVisibility::Withdrawn, $review->status); $this->assertSame($client->id, $review->withdrawn_by_user_id);
        $this->get(route('reviews.show', $review))->assertOk()->assertSee('Histórico privado.')->assertSee('Ya no cuenta', false)->assertDontSee('type="submit">Retirar', false);
        $this->travel(1)->hours(); $this->patch(route('reviews.withdraw', $review))->assertStatus(303);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->post(route('reviews.store', $reservation), ['rating' => 2, 'comment' => 'Histórico privado.'])->assertStatus(303);
        $this->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(409);
        $this->get(route('reviews.create', $reservation))->assertRedirect(route('reviews.show', $review));
        $this->actingAs($provider)->get(route('reviews.show', $review))->assertForbidden();
        $this->get(route('reservations.show', $reservation))->assertOk()->assertDontSee('Histórico privado.')->assertDontSee(route('reviews.show', $review));
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_private_listing_filters_server_side_and_rate_limits_writes(): void
    {
        [$client, , , $reservation] = $this->reviewContext(); $this->publishReview($reservation, 4, 'MI-RESEÑA');
        [, , , $other] = $this->reviewContext(); $this->publishReview($other, 1, 'OTRA-RESEÑA-PRIVADA');
        $this->actingAs($client)->get(route('reviews.index', ['client_user_id' => $other->client_user_id]))->assertOk()->assertSee('MI-RESEÑA')->assertDontSee('OTRA-RESEÑA-PRIVADA');
        for ($i = 0; $i < 10; $i++) $this->postJson(route('reviews.store', $reservation), ['rating' => 0])->assertUnprocessable();
        $this->postJson(route('reviews.store', $reservation), ['rating' => 4])->assertStatus(429)->assertHeader('Retry-After');
        $this->patchJson(route('reviews.withdraw', $reservation->review))->assertStatus(429);
        foreach (['page=-1', 'page[]=1', 'page=10001'] as $query) $this->getJson('/cuenta/resenas?'.$query)->assertUnprocessable();
        $this->get(route('reviews.index', ['page' => 2]))->assertNotFound();
    }
}
