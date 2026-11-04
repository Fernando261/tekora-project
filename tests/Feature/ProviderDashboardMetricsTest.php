<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\InquiryReadTracking;
use App\Services\InquiryWorkflow;
use App\Services\ProviderDashboardMetrics;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

class ProviderDashboardMetricsTest extends TestCase
{
    use DashboardFixtures;

    public function test_ten_empty_indicators_distinguish_current_state_and_period_without_inventing_rates(): void
    {
        $provider = $this->account(RoleName::Provider);
        $provider->providerProfile()->create(['display_name' => 'Taller nuevo']);
        $data = app(ProviderDashboardMetrics::class)->build($provider);
        $this->assertSame(30, $data['window']['days']);
        $this->assertSame(['published' => 0, 'received' => 0, 'pending' => 0, 'acceptance' => 'Sin decisiones', 'requested' => 0,
            'upcoming' => 0, 'completed' => 0, 'cancellation' => 'Sin reservas finalizadas', 'rating' => 'Sin calificaciones', 'unread' => 0], $this->values($data));
        $this->assertCount(6, array_filter($data['metrics'], fn ($metric) => $metric['scope'] === 'current'));
        $this->assertCount(4, array_filter($data['metrics'], fn ($metric) => $metric['scope'] === 'period'));
        $this->assertSame('0 reseñas consideradas', $data['metrics'][8]['note']);
        $this->assertCount(30, $data['series']); $this->assertSame(0, array_sum(array_column($data['series'], 'count')));
        $this->assertSame([], $data['agenda']); $this->assertSame([], $data['attention']);
    }

    public function test_published_services_use_the_public_scope_and_ignore_the_period(): void
    {
        $this->travelTo(Carbon::parse('2029-01-01', 'UTC')); $provider = $this->account(RoleName::Provider);
        $this->service($provider); $this->service($provider);
        $this->service($provider, ['status' => 'draft']); $this->service($provider, ['status' => 'paused']);
        $hidden = $this->category('oculta'); $hidden->update(['is_active' => false]);
        $this->service($provider, ['service_category_id' => $hidden->id]); $this->service($this->account(RoleName::Provider));
        $this->travelTo(Carbon::parse('2030-04-10 18:00:00', 'UTC'));
        foreach ([7, 30, 90] as $days) $this->assertSame(2, $this->values($this->dashboard($provider, $days))['published']);
    }

    public function test_received_uses_utc_bounds_of_local_days_and_pending_is_current(): void
    {
        $provider = $this->account(RoleName::Provider);
        foreach (['2030-01-01 12:00:00', '2030-04-04 05:59:59', '2030-04-04 06:00:00', '2030-04-10 18:00:00', '2030-04-10 18:00:01'] as $time) {
            $this->travelTo(Carbon::parse($time, 'UTC')); $this->inquiry(null, $provider);
        }
        $this->travelTo(Carbon::parse('2030-04-10 18:00:00', 'UTC')); $data = $this->dashboard($provider, 7);
        $this->assertSame('2030-04-04 06:00:00', $data['window']['start_utc']);
        $this->assertSame('2030-04-10 18:00:00', $data['window']['end_utc']);
        $this->assertSame(2, $this->values($data)['received']); $this->assertSame(5, $this->values($data)['pending']);
    }

    public function test_seven_thirty_and_ninety_days_include_today_and_exactly_the_selected_calendar_days(): void
    {
        $provider = $this->account(RoleName::Provider); $now = Carbon::parse('2030-04-10 18:00:00', 'UTC');
        foreach ([91, 89, 29, 6, 0] as $ago) { $this->travelTo($now->copy()->subDays($ago)); $this->inquiry(null, $provider); }
        $this->travelTo($now);
        foreach ([7 => 2, 30 => 3, 90 => 4] as $days => $expected) {
            $data = $this->dashboard($provider, $days); $this->assertSame($expected, $this->values($data)['received']);
            $this->assertCount($days, $data['series']); $this->assertSame(5, $this->values($data)['pending']);
        }
    }

    public function test_acceptance_keeps_completed_acceptances_and_uses_decision_dates_not_creation_or_current_status(): void
    {
        $provider = $this->account(RoleName::Provider); $workflow = app(InquiryWorkflow::class);
        $this->travelTo(Carbon::parse('2030-03-01 18:00:00', 'UTC'));
        $old = $this->inquiry(null, $provider, InquiryStatus::Accepted);
        $decidedToday = $this->inquiry(null, $provider);
        $this->travelTo(Carbon::parse('2030-04-10 18:00:00', 'UTC'));
        $workflow->decide($provider, $old->id, InquiryAction::Complete);
        $workflow->decide($provider, $decidedToday->id, InquiryAction::Accept);
        $workflow->decide($provider, $decidedToday->id, InquiryAction::Complete);
        $this->inquiry(null, $provider, InquiryStatus::Accepted); $this->inquiry(null, $provider, InquiryStatus::Rejected);
        $this->inquiry(null, $provider); $this->inquiry(null, $provider, InquiryStatus::Cancelled);
        $data = $this->dashboard($provider, 7);
        $this->assertSame('66.7%', $this->values($data)['acceptance']); $this->assertSame('2 de 3 decisiones', $data['metrics'][3]['note']);
        $this->assertSame(1, $this->values($data)['pending']);
        $this->assertSame('2030-04-10 18:00:00', $decidedToday->fresh()->getRawOriginal('accepted_at'));
        $this->assertSame('Sin reservas finalizadas', $this->values($data)['cancellation']);
        $this->assertSame(0, $this->values($data)['completed']); // Finalizar una solicitud sin reserva no inventa un servicio completado.
    }

    public function test_pending_and_never_accepted_cancellations_do_not_create_acceptance_decisions(): void
    {
        $provider = $this->account(RoleName::Provider); $this->inquiry(null, $provider); $this->inquiry(null, $provider, InquiryStatus::Cancelled);
        $this->assertSame('Sin decisiones', $this->values($this->dashboard($provider))['acceptance']);
    }

    public function test_reservation_state_indicators_require_strictly_future_starts(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $workflow = app(ReservationWorkflow::class);
        $first = $this->proposeReservation($inquiry, ['time' => '08:00']);
        $workflow->decide($provider, $first->id, 'reject', ['version' => 1]);
        $second = $this->proposeReservation($inquiry, ['time' => '09:00']); $workflow->decide($provider, $second->id, 'confirm', ['version' => 1]);
        $other = $this->inquiry(null, $provider, InquiryStatus::Accepted); $requested = $this->proposeReservation($other, ['time' => '10:00']);
        $other2 = $this->inquiry(null, $provider, InquiryStatus::Accepted); $confirmed = $this->proposeReservation($other2, ['time' => '11:00']);
        $workflow->decide($provider, $confirmed->id, 'confirm', ['version' => 1]);
        $other3 = $this->inquiry(null, $provider, InquiryStatus::Accepted); $cancelled = $this->proposeReservation($other3, ['time' => '12:00']);
        $workflow->decide($provider, $cancelled->id, 'cancel', ['version' => 1]);
        $this->travelTo(Carbon::parse($second->starts_at_utc, 'UTC'));
        $values = $this->values($this->dashboard($provider)); $this->assertSame(1, $values['requested']); $this->assertSame(1, $values['upcoming']);
        $this->travelTo(Carbon::parse($requested->starts_at_utc, 'UTC'));
        $values = $this->values($this->dashboard($provider)); $this->assertSame(0, $values['requested']); $this->assertSame(1, $values['upcoming']);
    }

    public function test_real_completed_and_cancelled_events_include_both_automatic_inquiry_closures_and_exclude_rejections(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $workflow = app(ReservationWorkflow::class);
        $rejected = $this->proposeReservation($inquiry); $workflow->decide($provider, $rejected->id, 'reject', ['version' => 1]);
        $cancelled = $this->proposeReservation($inquiry); $workflow->decide($provider, $cancelled->id, 'cancel', ['version' => 1]);
        $completed = $this->completeForReview($inquiry);
        $autoCancel = $this->inquiry(null, $provider, InquiryStatus::Accepted); $this->proposeReservation($autoCancel, ['date' => '2030-01-08']);
        app(InquiryWorkflow::class)->decide($provider, $autoCancel->id, InquiryAction::Complete);
        $autoComplete = $this->inquiry(null, $provider, InquiryStatus::Accepted); $reservation = $this->proposeReservation($autoComplete, ['date' => '2030-01-09']);
        $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(InquiryWorkflow::class)->decide($provider, $autoComplete->id, InquiryAction::Complete);
        $data = $this->dashboard($provider); $this->assertSame(2, $this->values($data)['completed']);
        $this->assertSame('50.0%', $this->values($data)['cancellation']); $this->assertSame('2 de 4 reservas finalizadas', $data['metrics'][7]['note']);
        $this->assertSame(2, array_sum(array_column($data['series'], 'count')));
    }

    public function test_rating_is_weighted_current_and_excludes_withdrawn_or_invisible_services(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $withdrawn = $this->publishReview($reservation, 5, 'RETIRADA-PRIVADA');
        $this->publishReview($this->completeForReview($inquiry), 1); $this->publishReview($this->completeForReview($inquiry), 3);
        $this->assertSame('3.0 / 5', $this->values($this->dashboard($provider, 7))['rating']);
        app(ReviewWorkflow::class)->withdraw($client, $withdrawn->id);
        $this->travelTo(Carbon::parse('2031-04-10 18:00:00', 'UTC'));
        foreach ([7, 30, 90] as $days) {
            $data = $this->dashboard($provider, $days); $this->assertSame('2.0 / 5', $this->values($data)['rating']);
            $this->assertSame('2 reseñas consideradas', $data['metrics'][8]['note']);
        }
        $inquiry->service->update(['status' => 'paused']); $this->assertSame('Sin calificaciones', $this->values($this->dashboard($provider))['rating']);
        $inquiry->service->update(['status' => 'published']); $inquiry->service->category->update(['is_active' => false]);
        $this->assertSame('Sin calificaciones', $this->values($this->dashboard($provider))['rating']);
    }

    public function test_unread_reuses_markers_and_only_counts_provider_side_messages_for_a_dual_account(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider); $otherProvider = $this->account(RoleName::Provider);
        $received = $this->inquiry(null, $dual); $sent = $this->inquiry($dual, $otherProvider);
        $this->message($received->client, $received, 'CUERPO-PRIVADO'); $this->message($received->client, $received);
        $this->message($dual, $received); $this->message($otherProvider, $sent); $this->message($otherProvider, $sent);
        $this->assertSame(4, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->assertSame(2, $this->values($this->dashboard($dual))['unread']);
        app(InquiryReadTracking::class)->mark($dual, $received->id, $this->readToken($dual, $received));
        $this->assertSame(0, $this->values($this->dashboard($dual))['unread']);
        $this->message($received->client, $received); $this->assertSame(1, $this->values($this->dashboard($dual))['unread']);
        $this->actingAs($dual)->get(route('provider.dashboard'))->assertOk()->assertDontSee('CUERPO-PRIVADO');
    }

    public function test_chart_buckets_follow_local_midnight_without_overlap_and_total_matches_completed(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $workflow = app(ReservationWorkflow::class);
        $a = $this->proposeReservation($inquiry, ['date' => '2030-01-04']); $workflow->decide($provider, $a->id, 'confirm', ['version' => 1]);
        $other = $this->inquiry(null, $provider, InquiryStatus::Accepted);
        $b = $this->proposeReservation($other, ['date' => '2030-01-04', 'time' => '11:00']); $workflow->decide($provider, $b->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse('2030-01-06 05:59:59', 'UTC')); $workflow->decide($provider, $a->id, 'complete', ['version' => 2]);
        $this->travelTo(Carbon::parse('2030-01-06 06:00:00', 'UTC')); $workflow->decide($provider, $b->id, 'complete', ['version' => 2]);
        $data = $this->dashboard($provider, 7); $counts = array_column($data['series'], 'count', 'date');
        $this->assertSame(1, $counts['2030-01-05']); $this->assertSame(1, $counts['2030-01-06']);
        $this->assertSame(2, array_sum($counts)); $this->assertSame(2, $this->values($data)['completed']);
        $this->assertSame(['2029-12-31', '2030-01-01', '2030-01-02', '2030-01-03', '2030-01-04', '2030-01-05', '2030-01-06'], array_keys($counts));
        $this->travelTo(Carbon::parse('2030-01-06 05:59:59', 'UTC'));
        $this->assertSame(1, $this->values($this->dashboard($provider, 7))['completed']); // Excluye el evento posterior al instante consultado.
        $this->travelTo(Carbon::parse('2030-01-12 18:00:00', 'UTC'));
        $this->assertSame(1, $this->values($this->dashboard($provider, 7))['completed']); // Incluye solo la transición desde la medianoche exacta.
    }

    public function test_calendar_window_is_independent_of_app_timezone_and_honors_historical_mexico_offsets(): void
    {
        $provider = $this->account(RoleName::Provider); $this->inquiry(null, $provider);
        $this->travelTo(Carbon::parse('2022-11-01 03:00:00', 'UTC'));
        $before = $this->dashboard($provider, 7); $oldTimezone = date_default_timezone_get();
        try {
            config(['app.timezone' => 'Asia/Tokyo']); date_default_timezone_set('Asia/Tokyo');
            $after = $this->dashboard($provider, 7);
            $this->assertSame('2022-10-25 05:00:00', $after['window']['start_utc']);
            $this->assertSame('2022-11-01 03:00:00', $after['window']['end_utc']);
            $this->assertSame($this->values($before), $this->values($after)); $this->assertSame($before['series'], $after['series']);
        } finally { date_default_timezone_set($oldTimezone); }
    }

    public function test_rating_uses_all_visible_reviews_with_equal_weight_and_rejects_incoherent_legacy_rows(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation, 5); $this->publishReview($this->completeForReview($inquiry), 5);
        $other = $this->inquiry($client, $provider, InquiryStatus::Accepted); $r = $this->completeForReview($other); $this->publishReview($r, 2);
        $this->assertSame('4.0 / 5', $this->values($this->dashboard($provider))['rating']);
        $invalid = $this->completeForReview($other); $stranger = $this->account(RoleName::Client);
        // Solo en memoria: simular un registro heredado que perdió la correspondencia de cliente con su reserva.
        DB::unprepared('DROP TRIGGER reviews_completed_identity_insert');
        DB::table('service_reviews')->insert(['service_reservation_id' => $invalid->id, 'service_id' => $invalid->service_id,
            'provider_profile_id' => $invalid->provider_profile_id, 'client_user_id' => $stranger->id, 'rating' => 1, 'created_at' => $invalid->updated_at]);
        $data = $this->dashboard($provider); $this->assertSame('4.0 / 5', $this->values($data)['rating']);
        $this->assertSame('3 reseñas consideradas', $data['metrics'][8]['note']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check')); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
    }
}
