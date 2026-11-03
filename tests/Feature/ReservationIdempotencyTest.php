<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceReservation;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationIdempotencyTest extends TestCase
{
    use ReservationFixtures;

    public function test_identical_http_retries_return_original_even_after_rejection_or_inquiry_closure(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $data = $this->reservationData();
        $this->actingAs($client)->post(route('reservations.store', $inquiry), $data)->assertStatus(303);
        $reservation = ServiceReservation::sole();
        $this->post(route('reservations.store', $inquiry), $data)->assertRedirect(route('reservations.show', $reservation));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'reject', ['version' => 1]);
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $this->publishSchedule($provider, 'UTC', 1);
        $this->post(route('reservations.store', $inquiry), $data)->assertRedirect(route('reservations.show', $reservation));
        $this->assertDatabaseCount('service_reservations', 1); $this->assertDatabaseCount('reservation_events', 2);
        $this->assertSame('rejected', $reservation->fresh()->status->value);
    }

    public function test_key_reuse_with_different_proposal_or_revision_conflicts_and_is_case_normalized(): void
    {
        [$client, , $inquiry] = $this->reservationContext(); $data = $this->reservationData();
        $this->actingAs($client)->post(route('reservations.store', $inquiry), $data)->assertStatus(303);
        $this->post(route('reservations.store', $inquiry), array_replace($data, ['idempotency_key' => strtoupper($data['idempotency_key'])]))->assertStatus(303);
        foreach ([['date' => '2030-01-08'], ['time' => '10:00'], ['availability_revision' => 2]] as $change) {
            $this->postJson(route('reservations.store', $inquiry), array_replace($data, $change))->assertStatus(409);
        }
        $this->assertDatabaseCount('service_reservations', 1); $this->assertDatabaseCount('reservation_events', 1);
    }

    public function test_same_key_is_scoped_to_client_and_inquiry_and_different_keys_do_not_duplicate_active_proposals(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $data = $this->reservationData();
        $workflow = app(ReservationWorkflow::class); $first = $workflow->propose($client, $inquiry->id, $data);
        $this->assertTrue($first['created']); $this->assertFalse($workflow->propose($client, $inquiry->id, $data)['created']);
        $this->actingAs($client)->postJson(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(409);
        $otherClient = $this->account(RoleName::Client); $otherInquiry = $this->inquiry($otherClient, $provider, InquiryStatus::Accepted);
        $this->assertTrue($workflow->propose($otherClient, $otherInquiry->id, $data)['created']);
        $this->assertDatabaseCount('service_reservations', 2);
    }
}
