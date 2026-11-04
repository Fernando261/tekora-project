<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Models\ServiceReview;
use App\Services\InquiryMessaging;
use App\Services\InquiryReadTracking;
use App\Services\ReviewWorkflow;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\EnforcedCsrf;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewCsrfCompatibilityTest extends TestCase
{
    use ReviewFixtures;

    public function test_real_csrf_protects_publication_and_withdrawal_and_form_needs_no_javascript(): void
    {
        [$client, , , $reservation] = $this->reviewContext(); $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->actingAs($client)->withSession(['_token' => 'review-csrf'])->get(route('reviews.create', $reservation))->assertOk()
            ->assertSee('name="_token"', false)->assertSee('method="POST"', false)->assertSee('type="radio"', false)->assertDontSee('onclick=', false);
        $this->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(419);
        $this->post(route('reviews.store', $reservation), ['rating' => 5, '_token' => 'wrong'])->assertStatus(419);
        $this->post(route('reviews.store', $reservation), ['rating' => 5, '_token' => 'review-csrf'])->assertStatus(303);
        $review = ServiceReview::sole();
        $this->patch(route('reviews.withdraw', $review))->assertStatus(419);
        $this->patch(route('reviews.withdraw', $review), ['_token' => 'wrong'])->assertStatus(419);
        $this->patch(route('reviews.withdraw', $review), ['_token' => 'review-csrf'])->assertStatus(303);
        $this->assertSame('withdrawn', $review->fresh()->status->value);
        $this->delete(route('reviews.show', $review), ['_token' => 'review-csrf'])->assertStatus(405);
    }

    public function test_publishing_and_withdrawing_leave_every_previous_workflow_and_counter_unchanged(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $key = (string) \Illuminate\Support\Str::uuid(); $message = $this->message($provider, $inquiry, 'Texto privado anterior a las reseñas.', $key);
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $tables = ['users', 'roles', 'role_user', 'provider_profiles', 'services', 'service_inquiries', 'inquiry_notifications', 'service_inquiry_messages', 'service_inquiry_reads',
            'provider_availability_settings', 'provider_weekly_intervals', 'provider_blocked_dates', 'service_reservations', 'reservation_events'];
        $before = $this->reservationRows($tables); $review = $this->publishReview($reservation);
        $this->actingAs($client)->get(route('reviews.index'))->assertOk(); $this->get(route('reviews.show', $review))->assertOk();
        app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $this->get(route('reservations.show', $reservation))->assertOk(); $this->get(route('inquiries.show', $inquiry))->assertOk();
        $this->get(route('catalog.show', $reservation->service_id))->assertOk();
        $this->assertSame($before, $this->reservationRows($tables));
        $retry = app(InquiryMessaging::class)->send($provider, $inquiry->id, 'Texto privado anterior a las reseñas.', $key);
        $this->assertFalse($retry['created']); $this->assertSame($message->id, $retry['message']->id);
        $this->assertSame(0, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->publishSchedule($provider, 'Asia/Tokyo', 1, ['intervals' => []]);
        $this->assertSame($review->rating, $review->fresh()->rating); $this->assertSame('withdrawn', $review->fresh()->status->value);
        $this->assertSame('completed', $reservation->fresh()->status->value);
    }

    public function test_legacy_identity_with_matching_email_cannot_supply_or_access_verified_reviews(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext();
        $legacy = Profesionista::create(['nombre' => 'Profesional antiguo', 'email' => $client->email, 'cedula' => 'REVIEW-LEGACY', 'password' => Hash::make('separate-legacy-password'),
            'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        $tables = ['profesionistas', 'profesionista_disponibilidades', 'profesionista_bloqueos', 'citas']; $before = $this->reservationRows($tables);
        $this->get(route('catalog.show', $reservation->service_id))->assertOk()->assertSee('Aún no hay reseñas verificadas');
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'separate-legacy-password'])->assertRedirect('/dashboard-profesionista');
        $this->postJson(route('reviews.store', $reservation), ['rating' => 5])->assertUnauthorized();
        $this->get(route('reviews.index'))->assertRedirect('/login');
        $review = $this->publishReview($reservation);
        $this->getJson(route('reviews.show', $review))->assertUnauthorized();
        $this->patchJson(route('reviews.withdraw', $review))->assertUnauthorized();
        $this->get('/profesionista/disponibilidad')->assertOk();
        $this->assertSame($before, $this->reservationRows($tables)); $this->assertGuest('web');
    }
}
