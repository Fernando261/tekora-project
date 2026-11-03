<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Services\InquiryWorkflow;
use App\Services\PublicReviews;
use App\Services\ReviewWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewPublicVisibilityTest extends TestCase
{
    use ReviewFixtures;

    public function test_empty_public_views_do_not_treat_zero_reviews_as_a_negative_rating(): void
    {
        [, $provider, $inquiry] = $this->reviewContext();
        foreach ([route('catalog.show', $inquiry->service_id), route('public-providers.show', $provider->providerProfile)] as $url) {
            $this->get($url)->assertOk()->assertSee('Aún no hay reseñas verificadas')->assertDontSee('0.0 / 5');
        }
        $summary = app(PublicReviews::class)->forService($inquiry->service_id);
        $this->assertSame(0, $summary['count']); $this->assertNull($summary['average']);
    }

    public function test_public_reviews_are_anonymous_escaped_and_contain_no_reservation_links_or_dates(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext();
        $comment = '<script>alert("x")</script> <b>Texto plano</b> **sin Markdown**';
        $this->publishReview($reservation, 5, $comment);
        foreach ([route('catalog.show', $reservation->service_id), route('public-providers.show', $provider->providerProfile)] as $url) {
            $this->get($url)->assertOk()->assertSee('Cliente verificado')->assertSee('Servicio verificado')->assertSee('5.0 / 5')
                ->assertSee('aria-label="5.0 de 5 estrellas"', false)->assertSee($comment)->assertDontSee('<script>alert', false)
                ->assertDontSee('<b>Texto plano</b>', false)->assertSee('enero de 2030')->assertDontSee($client->name)->assertDontSee($client->email)
                ->assertDontSee($reservation->starts_at_utc)->assertDontSee('/cuenta/reservas/')->assertDontSee(route('inquiries.show', $reservation->service_inquiry_id));
        }
        $data = app(PublicReviews::class)->forProvider($provider->providerProfile->id)['reviews']->items()[0];
        $this->assertSame(['rating', 'comment', 'month', 'service_id', 'service_title'], array_keys($data));
    }

    public function test_service_means_and_provider_weighted_mean_use_only_visible_valid_reviews(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation, 5); $this->publishReview($this->completeForReview($inquiry), 5); $this->publishReview($this->completeForReview($inquiry), 1);
        $serviceB = $this->service($provider, ['title' => 'Otro servicio', 'service_category_id' => $this->category('otra')->id]);
        $workflow = app(InquiryWorkflow::class); $inquiryB = $workflow->open($client, $serviceB->id, 'Necesito otro servicio.')['inquiry'];
        $workflow->decide($provider, $inquiryB->id, InquiryAction::Accept);
        $this->publishReview($this->completeForReview($inquiryB), 1);
        $public = app(PublicReviews::class);
        $this->assertSame(3, $public->forService($inquiry->service_id)['count']); $this->assertSame(3.7, $public->forService($inquiry->service_id)['average']);
        $this->assertSame(4, $public->forProvider($provider->providerProfile->id)['count']); $this->assertSame(3.0, $public->forProvider($provider->providerProfile->id)['average']);
        $serviceB->update(['status' => 'paused']);
        $this->assertNull($public->forService($serviceB->id));
        $this->assertSame(3.7, $public->forProvider($provider->providerProfile->id)['average']);
        $serviceB->update(['status' => 'published']); $serviceB->category->update(['is_active' => false]);
        $this->assertSame(3, $public->forProvider($provider->providerProfile->id)['count']);
        $this->assertDatabaseCount('service_reviews', 4);
    }

    public function test_withdrawal_removes_public_text_counts_and_average_without_releasing_the_reservation(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 5, 'RETIRADA-SECRETA');
        $other = $this->completeForReview($inquiry); $this->publishReview($other, 1, 'Comentario que sigue publicado.');
        $public = app(PublicReviews::class); $this->assertSame(3.0, $public->forService($reservation->service_id)['average']);
        app(ReviewWorkflow::class)->withdraw($client, $review->id);
        foreach ([route('catalog.show', $reservation->service_id), route('public-providers.show', $provider->providerProfile)] as $url) {
            $this->get($url)->assertOk()->assertSee('1.0 / 5')->assertSee('1 reseña verificada')->assertDontSee('RETIRADA-SECRETA');
        }
        $this->actingAs($provider)->get(route('public-providers.show', $provider->providerProfile))->assertOk()->assertDontSee('RETIRADA-SECRETA');
        $this->get(route('reviews.show', $review))->assertForbidden();
        $this->assertSame(1, $public->forService($reservation->service_id)['count']);
        $this->assertDatabaseCount('service_reviews', 2);
    }

    public function test_paused_service_inactive_category_and_provider_role_revocation_hide_public_reviews(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 2, 'Visible solo en catálogo autorizado.');
        $service = $inquiry->service;
        foreach (['paused', 'draft'] as $state) {
            $service->update(['status' => $state]);
            $this->get(route('catalog.show', $service))->assertNotFound(); $this->get(route('public-providers.show', $provider->providerProfile))->assertNotFound();
            $this->assertNull(app(PublicReviews::class)->forService($service->id));
        }
        $service->update(['status' => 'published']); $service->category->update(['is_active' => false]);
        $this->get(route('catalog.show', $service))->assertNotFound();
        $service->category->update(['is_active' => true]); $provider->roles()->detach($provider->roles()->where('slug', 'provider')->value('roles.id'));
        $this->get(route('catalog.show', $service))->assertNotFound(); $this->assertNull(app(PublicReviews::class)->forProvider($provider->providerProfile->id));
        $this->actingAs($client)->get(route('reviews.show', $review))->assertOk()->assertSee('Visible solo en catálogo autorizado.');
        $this->assertDatabaseCount('service_reviews', 1);
    }

    public function test_completed_service_can_be_reviewed_privately_after_its_publication_is_paused(): void
    {
        [$client, , $inquiry, $reservation] = $this->reviewContext(); $inquiry->service->update(['status' => 'paused']);
        $this->actingAs($client)->post(route('reviews.store', $reservation), ['rating' => 4])->assertStatus(303);
        $this->assertNull(app(PublicReviews::class)->forService($inquiry->service_id));
        $this->get(route('reviews.show', $reservation->review))->assertOk()->assertSee('4 / 5');
    }

    public function test_public_pagination_and_private_pagination_are_bounded_and_independent(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $this->publishReview($reservation, 5, 'PRIMER-COMENTARIO');
        for ($i = 0; $i < 12; $i++) $this->publishReview($this->completeForReview($inquiry), 4, 'Comentario '.$i);
        $url = route('catalog.show', $inquiry->service_id);
        $this->get($url)->assertOk()->assertSee('13 reseñas verificadas')->assertSee('Página 1 de 3')->assertDontSee('PRIMER-COMENTARIO');
        $this->get($url.'?reviews_page=3')->assertOk()->assertSee('PRIMER-COMENTARIO')->assertSee('Página 3 de 3');
        $this->get($url.'?reviews_page=4')->assertNotFound();
        foreach (['reviews_page=-1', 'reviews_page[]=1', 'reviews_page=10001'] as $query) $this->getJson($url.'?'.$query)->assertUnprocessable();
        $this->get(route('public-providers.show', $provider->providerProfile, false).'?page=1&reviews_page=2')->assertOk()->assertSee('reviews_page=3')->assertSee('Página 2 de 3');
        $this->actingAs($client)->get(route('reviews.index'))->assertOk()->assertSee('Página 1 de 2');
        $this->get(route('reviews.index', ['page' => 2]))->assertOk()->assertSee('PRIMER-COMENTARIO');
    }

    public function test_public_queries_have_fixed_count_and_never_load_private_identity_columns(): void
    {
        [, $provider, $inquiry, $reservation] = $this->reviewContext(); $this->publishReview($reservation); $profileId = $provider->providerProfile->id;
        DB::enableQueryLog(); DB::flushQueryLog();
        $public = app(PublicReviews::class); $public->forProvider($profileId); $small = DB::getQueryLog(); DB::disableQueryLog();
        for ($i = 0; $i < 6; $i++) $this->publishReview($this->completeForReview($inquiry));
        DB::enableQueryLog(); DB::flushQueryLog();
        $data = $public->forProvider($profileId); $large = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(4, $small); $this->assertCount(count($small), $large); $this->assertCount(5, $data['reviews']);
        foreach ($large as $query) {
            $this->assertStringNotContainsString('"email"', $query['query']); $this->assertStringNotContainsString('"name"', $query['query']);
        }
        $this->assertSame(7, $data['count']);
    }

    public function test_published_review_survives_availability_changes_and_later_inquiry_completion(): void
    {
        [, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 4, 'Experiencia ya completada.');
        $before = [$review->getAttributes(), $reservation->getAttributes(), $reservation->events()->get()->toArray()];
        $this->publishSchedule($provider, 'Pacific/Auckland', 1, ['intervals' => [], 'blocked_dates' => ['2030-01-07']]);
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $this->assertSame($before, [$review->fresh()->getAttributes(), $reservation->fresh()->getAttributes(), $reservation->events()->get()->toArray()]);
        $this->get(route('catalog.show', $reservation->service_id))->assertOk()->assertSee('4.0 / 5')->assertSee('Experiencia ya completada.');
        $this->assertSame(1, app(PublicReviews::class)->forProvider($provider->providerProfile->id)['count']);
    }
}
