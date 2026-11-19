<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\ContractingJourney;
use App\Services\ReservationWorkflow;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ContractingJourneyPolishTest extends TestCase
{
    use SlotHoldFixtures;

    private function confirmed(): array
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        $workflow = app(ReservationWorkflow::class);
        $workflow->decide($provider, $r->id, 'confirm', $this->holdInput());
        $workflow->decide($client, $r->id, 'commit', $this->holdInput());
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 230000, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $other = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);

        return [$client, $provider, $inquiry, $quote, $r, $other];
    }

    private function document(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    #[DataProvider('actors')]
    public function test_confirmed_price_is_linked_and_catalogue_issuance_is_secondary_for_each_actor(string $role): void
    {
        [$client, $provider, $inquiry, $quote, $r, $other] = $this->confirmed();
        $actor = $role === 'client' ? $client : $provider;
        $response = $this->actingAs($actor, 'web')->get(route('inquiries.show', $inquiry))->assertOk();
        $xpath = $this->document($response->getContent());
        $price = $xpath->query('//*[contains(@class,"cj-overview")]//*[contains(@class,"cj-agreed-price")]')->item(0);
        $this->assertNotNull($price);
        $this->assertStringContainsString('$1,000.00 MXN', $price->textContent);
        $this->assertStringNotContainsString('$2,300.00 MXN', $price->textContent);
        $this->assertStringContainsString('no cambia por nuevas versiones del catálogo', mb_strtolower($price->textContent));
        $this->assertSame(1, $xpath->query('//*[@id="cotizaciones"]//*[contains(@class,"cj-quote-agreement")]//a[@href="'.route('quotes.show', ['quote' => $quote->id, 'follow' => 1]).'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="cotizaciones"]//form[not(ancestor::details)]')->length);
        $this->assertSame($role === 'client' ? 1 : 0, $xpath->query('//*[@id="cotizaciones"]//details[not(@open)]//form')->length);
        $this->assertSame(1, $xpath->query('//*[@id="cotizaciones"]//ul[contains(@class,"quote-history") and not(ancestor::details)]')->length);
        $this->assertSame(2, $xpath->query('//*[@id="cotizaciones"]//ul[contains(@class,"quote-history")]/li')->length);
        $response->assertDontSee('Consultar nueva cotización')->assertSee('Vinculada a esta reserva')->assertSee('Cotización independiente');
        $j = $response->viewData('journey');
        $this->assertSame('wait_service', $j['next_action']['code']);
        $this->assertSame($quote->id, $j['commercial']['quote']['id']);
        $this->assertSame(100000, $j['commercial']['agreed_amount']['total_cents']);
        $this->assertFalse($j['payments']['enabled']);
        $this->assertSame('confirmed', $r->fresh()->effectiveStatus()->value);
        $this->assertHoldIntegrity();
    }

    public static function actors(): array
    {
        return [['client'], ['provider']];
    }

    public function test_accepting_an_additional_quote_cannot_change_the_confirmed_reservation_or_api_amount(): void
    {
        [$client, $provider, $inquiry, $quote, $r, $other] = $this->confirmed();
        $snapshot = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_occupancy']);
        $this->actingAs($client, 'web')->get(route('quotes.show', $other->id))->assertOk()->assertSee('Cotización independiente de tu reserva')->assertSee('Precio acordado de la reserva:')->assertSee('$1,000.00 MXN')->assertSee('$2,300.00 MXN')->assertDontSee('No se ha creado una reserva');
        $this->post(route('quotes.accept', $other->id), ['acknowledge' => 1, '_journey' => 1])->assertRedirect(route('inquiries.show', $inquiry).'#cotizaciones');
        $this->assertSame($snapshot, $this->reservationRows(array_keys($snapshot)));
        $j = app(ContractingJourney::class)->forInquiry($client, $inquiry->id);
        $this->assertSame($quote->id, $j['commercial']['quote']['id']);
        $this->assertSame(100000, $j['commercial']['agreed_amount']['total_cents']);
        $this->get(route('quotes.show', $quote->id))->assertOk()->assertSee('Cotización vinculada a tu reserva')->assertDontSee('No se ha creado una reserva');
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))->assertOk()->assertExactJson(['data' => $j]);
        $this->assertHoldIntegrity();
    }

    public function test_completed_and_unpublished_services_keep_the_agreed_price_and_all_paginated_history(): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->confirmed();
        for ($i = 0; $i < 11; $i++) {
            app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        }
        $this->travelTo(Carbon::parse($r->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'complete', $this->holdInput(['version' => 2]));
        $inquiry->service->forceFill(['status' => 'draft'])->save();
        $one = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Precio acordado de esta reserva')->assertSee('$1,000.00 MXN')->assertSee('Comparte tu experiencia');
        $j = $one->viewData('journey');
        $this->assertSame('write_review', $j['next_action']['code']);
        $this->assertTrue($j['closure']['explicit_service_completed']);
        $this->assertSame(10, $this->document($one->getContent())->query('//*[@id="cotizaciones"]//ul[contains(@class,"quote-history")]/li')->length);
        $two = $this->get(route('inquiries.show', ['inquiry' => $inquiry->id, 'quotes_page' => 2]))->assertOk()->assertSee('Precio acordado de esta reserva');
        $this->assertSame(3, $this->document($two->getContent())->query('//*[@id="cotizaciones"]//ul[contains(@class,"quote-history")]/li')->length);
        $this->assertHoldIntegrity();
    }

    #[DataProvider('alternatives')]
    public function test_nonfinal_states_do_not_gain_a_confirmed_price_or_hide_the_original_next_step(string $phase): void
    {
        if ($phase === 'pending' || $phase === 'rejected-inquiry' || $phase === 'cancelled-inquiry') {
            $state = match ($phase) {
                'rejected-inquiry' => InquiryStatus::Rejected, 'cancelled-inquiry' => InquiryStatus::Cancelled, default => InquiryStatus::Pending
            };
            $inquiry = $this->inquiry(status: $state);
            $client = $inquiry->client;
        } else {
            [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
            $workflow = app(ReservationWorkflow::class);
            if ($phase === 'cancelled' || $phase === 'rejected') {
                $workflow->decide($phase === 'cancelled' ? $client : $provider, $r->id, $phase === 'cancelled' ? 'cancel' : 'reject', $this->holdInput());
            }
            if ($phase === 'held' || $phase === 'expired') {
                $workflow->decide($provider, $r->id, 'confirm', $this->holdInput());
                if ($phase === 'expired') {
                    $this->travel(900)->seconds();
                }
            }
        }
        $response = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk();
        $this->assertSame(0, $this->document($response->getContent())->query('//*[contains(@class,"cj-quote-agreement")]')->length);
        $this->assertSame(0, $this->document($response->getContent())->query('//*[contains(@class,"cj-other-quotes")]')->length);
        $j = $response->viewData('journey');
        $expected = match ($phase) {
            'pending' => 'wait_inquiry_decision', 'rejected-inquiry', 'cancelled-inquiry' => 'read_history', 'held' => 'confirm_reservation', 'requested' => 'wait_schedule_decision', default => 'propose_schedule'
        };
        $this->assertSame($expected, $j['next_action']['code']);
        $this->assertFalse($j['payments']['required_for_confirmation']);
        $this->assertHoldIntegrity();
    }

    public static function alternatives(): array
    {
        return array_map(fn ($s) => [$s], ['pending', 'rejected-inquiry', 'cancelled-inquiry', 'requested', 'held', 'expired', 'cancelled', 'rejected']);
    }

    public function test_confirmed_legacy_is_not_repriced_when_a_catalogue_price_appears(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $r = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', ['version' => 1]);
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 230000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $response = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Esta reserva conserva su acuerdo directo')->assertDontSee('Precio acordado de esta reserva');
        $this->assertSame('legacy', $response->viewData('journey')['commercial']['mode']);
        $this->assertNull($response->viewData('journey')['commercial']['agreed_amount']);
        $this->assertSame(1, $this->document($response->getContent())->query('//details[contains(@class,"cj-other-quotes")]//form')->length);
        $this->assertNull($r->fresh()->quote_id);
        $this->assertHoldIntegrity();
    }

    public function test_other_inquiry_ownership_remains_private_in_quote_details(): void
    {
        [$client, $provider, $inquiry, $quote, $r, $other] = $this->confirmed();
        $outsider = $this->account(\App\Enums\RoleName::Client);
        $this->actingAs($outsider, 'web')->get(route('quotes.show', $other->id))->assertForbidden()->assertDontSee('$1,000.00 MXN');
    }
}
