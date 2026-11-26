<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\ContractingJourney;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ContractingJourneyTest extends TestCase
{
    use SlotHoldFixtures;

    private function journey($user, $inquiry): array
    {
        return app(ContractingJourney::class)->forInquiry($user, $inquiry->id);
    }

    private function api($user, $inquiry)
    {
        return $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($user));
    }

    private function business(): array
    {
        return $this->reservationRows(['service_inquiries', 'service_inquiry_messages', 'service_quotes', 'quote_acceptances',
            'service_reservations', 'reservation_events', 'reservation_holds', 'reservation_hold_events', 'reservation_occupancy', 'service_reviews']);
    }

    public function test_complete_priced_journey_reuses_web_commands_and_mobile_read_model(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('issue_quote', $this->journey($client, $inquiry)['next_action']['code']);
        $issued = $this->actingAs($client, 'web')->post(route('quotes.issue', $inquiry), ['_journey' => '1', 'idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        $quote = DB::table('service_quotes')->first();
        $issued->assertRedirect(route('quotes.show', ['quote' => $quote->id, 'follow' => 1]));
        $this->assertSame('review_quote', $this->journey($client, $inquiry)['next_action']['code']);
        $this->get($issued->headers->get('Location'))->assertOk()->assertSee('name="_journey"', false);
        $this->post(route('quotes.accept', $quote->id), ['_journey' => 1, 'acknowledge' => 1])->assertRedirect(route('inquiries.show', $inquiry).'#cotizaciones');
        $accepted = $this->journey($client, $inquiry);
        $this->assertSame('propose_schedule', $accepted['next_action']['code']);
        $this->assertNull($accepted['reservation']);
        $this->assertSame(100000, $accepted['commercial']['agreed_amount']['total_cents']);
        $this->assertSame(15000, $accepted['commercial']['agreed_amount']['commission_cents']);
        $this->get(route('reservations.create', ['inquiry' => $inquiry->id, 'follow' => 1]))->assertOk()->assertSee('name="_journey"', false);
        $this->post(route('reservations.store', $inquiry), $this->reservationData(['quote_id' => $quote->id, '_journey' => 1]))->assertRedirect(route('inquiries.show', $inquiry).'#reservas');
        $reservation = $inquiry->reservations()->firstOrFail();
        $this->assertSame('wait_schedule_decision', $this->journey($client, $inquiry)['next_action']['code']);
        $this->assertSame('decide_schedule', $this->journey($provider, $inquiry)['next_action']['code']);
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $reservation), $this->holdInput(['_journey' => 1]))->assertRedirect(route('inquiries.show', $inquiry).'#reservas');
        $held = $this->journey($client, $inquiry);
        $this->assertSame('held', $held['reservation']['status']);
        $this->assertSame(900, $held['reservation']['hold']['remaining_seconds']);
        $this->assertSame('confirm_reservation', $held['next_action']['code']);
        $this->assertSame('current', $held['progress']['steps'][3]['status']);
        $this->assertSame('wait_client_confirmation', $this->journey($provider, $inquiry)['next_action']['code']);
        $this->actingAs($client, 'web')->patch(route('reservations.commit', $reservation), $this->holdInput(['version' => 1, '_journey' => 1]))->assertRedirect(route('inquiries.show', $inquiry).'#reservas');
        $this->assertSame('wait_service', $this->journey($client, $inquiry)['next_action']['code']);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));
        $this->assertFalse($this->journey($client, $inquiry)['closure']['explicit_service_completed']);
        $this->assertSame('record_service', $this->journey($provider, $inquiry)['next_action']['code']);
        $this->actingAs($provider, 'web')->patch(route('reservations.complete', $reservation), $this->holdInput(['version' => 2, '_journey' => 1]))->assertRedirect(route('inquiries.show', $inquiry).'#reservas');
        $this->assertSame('close_inquiry', $this->journey($provider, $inquiry)['next_action']['code']);
        $this->assertSame('write_review', $this->journey($client, $inquiry)['next_action']['code']);
        $this->patch(route('inquiries.complete', $inquiry))->assertRedirect(route('inquiries.show', $inquiry));
        $this->actingAs($client, 'web')->get(route('reviews.create', ['reservation' => $reservation->id, 'follow' => 1]))->assertOk()->assertSee('name="_journey"', false);
        $this->post(route('reviews.store', $reservation), ['rating' => 5, 'comment' => 'Experiencia sintética.', '_journey' => 1])->assertRedirect(route('inquiries.show', $inquiry).'#cierre');
        $review = $reservation->review()->firstOrFail();
        $this->assertSame('published', $this->journey($client, $inquiry)['review']['state']);
        $this->get(route('reviews.show', ['review' => $review->id, 'follow' => 1]))->assertOk()->assertSee('name="_journey"', false);
        $this->patch(route('reviews.withdraw', $review), ['_journey' => 1])->assertRedirect(route('inquiries.show', $inquiry).'#cierre');
        $final = $this->journey($client, $inquiry);
        $this->assertTrue($final['progress']['closed']);
        $this->assertSame('withdrawn', $final['review']['state']);
        $this->assertSame(array_fill(0, 5, 'complete'), array_column($final['progress']['steps'], 'status'));
        $this->api($client, $inquiry)->assertOk()->assertExactJson(['data' => $final]);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertViewHas('journey', $final)->assertSee('Consulta tu reseña retirada')->assertDontSee('/demo/checkout');
        $this->assertHoldIntegrity();
    }

    public function test_pending_roles_and_dual_accounts_follow_actual_participation(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $this->assertSame('wait_inquiry_decision', $this->journey($client, $inquiry)['next_action']['code']);
        $this->assertSame('provider', $this->journey($provider, $inquiry)['viewer_role']);
        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Como prestador')->assertSee('Aceptar solicitud')->assertDontSee('Consultar nueva cotización');
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Como cliente')->assertDontSee('Aceptar solicitud');
        $this->api($provider, $inquiry)->assertNotFound();
        $this->api($client, $inquiry)->assertOk()->assertJsonPath('data.viewer_role', 'client');
    }

    #[DataProvider('closedStates')]
    public function test_terminal_inquiry_preserves_history_without_invented_progress(InquiryStatus $state): void
    {
        $inquiry = $this->inquiry(status: $state);
        $j = $this->journey($inquiry->client, $inquiry);
        $this->assertSame('read_history', $j['next_action']['code']);
        $this->assertTrue($j['progress']['closed']);
        $this->assertFalse($j['closure']['explicit_service_completed']);
        $this->assertNull($j['commercial']['agreed_amount']);
        $this->actingAs($inquiry->client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Historial de decisiones')->assertSee('Conversación');
    }

    public static function closedStates(): array
    {
        return [[InquiryStatus::Rejected], [InquiryStatus::Cancelled]];
    }

    public function test_legacy_confirmation_does_not_gain_commercial_requirements_when_price_is_published_later(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $r = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', ['version' => 1]);
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $j = $this->journey($client, $inquiry);
        $this->assertSame('legacy', $j['commercial']['mode']);
        $this->assertSame('confirmed', $j['reservation']['status']);
        $this->assertNull($j['commercial']['agreed_amount']);
        $this->assertFalse($j['payments']['required_for_confirmation']);
    }

    #[DataProvider('reservationAbort')]
    public function test_aborted_reservation_keeps_its_history_and_offers_new_proposal(string $action): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($action === 'cancel' ? $client : $provider, $r->id, $action, $this->holdInput());
        $j = $this->journey($client, $inquiry);
        $this->assertSame($action === 'cancel' ? 'cancelled' : 'rejected', $j['reservation']['status']);
        $this->assertSame('propose_schedule', $j['next_action']['code']);
        $this->assertSame($r->id, $j['history']['reservations'][0]['id']);
        $this->assertFalse($j['closure']['explicit_service_completed']);
        $this->assertHoldIntegrity();
    }

    public static function reservationAbort(): array
    {
        return [['cancel'], ['reject']];
    }

    #[DataProvider('holdEnds')]
    public function test_released_and_expired_holds_are_read_truthfully_without_extending_lifetime(string $end): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', $this->holdInput());
        if ($end === 'released') {
            app(ReservationWorkflow::class)->decide($client, $r->id, 'cancel', $this->holdInput(['version' => 1]));
        } else {
            $this->travel(900)->seconds();
        }
        $before = $this->business();
        $j = $this->journey($client, $inquiry);
        $this->assertSame($end, $j['reservation']['hold']['state']);
        $this->assertSame('propose_schedule', $j['next_action']['code']);
        $this->assertNotContains('commit', $j['reservation']['actions']);
        $this->assertSame($before, $this->business());
        $this->api($client, $inquiry)->assertOk()->assertJsonPath('data.reservation.hold.state', $end);
        $this->assertSame($before, $this->business());
        $this->assertHoldIntegrity();
    }

    public static function holdEnds(): array
    {
        return [['released'], ['expired']];
    }

    public function test_reading_does_not_mark_messages_read_modify_workflows_or_complete_elapsed_service(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $r = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($r->ends_at_utc, 'UTC')->addDay());
        $before = $this->business();
        $this->api($client, $inquiry)->assertOk()->assertJsonPath('data.reservation.status', 'confirmed')->assertJsonPath('data.review.state', 'unavailable');
        $this->assertSame($before, $this->business());
        $this->assertSame('record_service', $this->journey($provider, $inquiry)['next_action']['code']);
        $this->assertHoldIntegrity();
    }

    public function test_expired_quote_and_new_published_prices_do_not_rewrite_accepted_amount(): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        $this->travel(2)->days();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 230000, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $j = $this->journey($client, $inquiry);
        $this->assertSame('accepted', $j['commercial']['quote']['state']);
        $this->assertSame(100000, $j['commercial']['agreed_amount']['total_cents']);
        $this->assertSame('requested', $j['reservation']['status']);
    }

    public function test_unpublished_availability_has_no_decorative_propose_action(): void
    {
        $inquiry = $this->inquiry(status: InquiryStatus::Accepted);
        $j = $this->journey($inquiry->client, $inquiry);
        $this->assertSame('wait_availability', $j['next_action']['code']);
        $this->assertFalse($j['commercial']['can_propose']);
        $this->actingAs($inquiry->client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('>Proponer un horario</a>', false);
    }

    public function test_api_requires_bearer_client_owner_and_does_not_disclose_other_requests(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey')->assertUnauthorized();
        $other = $this->account(RoleName::Client);
        $this->api($other, $inquiry)->assertNotFound();
        $this->api($provider, $inquiry)->assertNotFound();
        $this->getJson('/api/v1/inquiries/999999/journey', $this->bearer($client))->assertNotFound();
        $this->actingAs($other, 'web')->get(route('inquiries.show', $inquiry))->assertForbidden();
    }

    public function test_api_rejects_unexpected_input_and_contains_no_private_urls_models_or_fake_finance(): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey?client_user_id='.$provider->id, $this->bearer($client))->assertUnprocessable();
        $response = $this->api($client, $inquiry)->assertOk()->assertJsonPath('data.payments.enabled', false)->assertJsonPath('data.payments.required_for_confirmation', false);
        $json = $response->getContent();
        foreach (['http://', 'https://', 'password', 'email', 'remember_token', 'paid', 'captured', '<form'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringEndsWith('Z', $response->json('data.server_now'));
        $this->assertSame('reservation', $response->json('data.next_action.reference.type'));
        $this->assertCurrentApiInventory();
    }

    public function test_storage_failure_is_not_an_empty_or_successful_journey(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        DB::statement('DROP TABLE service_quotes');
        $this->api($client, $inquiry)->assertStatus(503)->assertJsonMissingPath('data.progress');
    }

    public function test_untrusted_return_flags_do_not_redirect_to_arbitrary_urls_or_reach_domain_commands(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $before = $this->business();
        $this->actingAs($client, 'web')->post(route('reservations.store', $inquiry), $this->reservationData(['_journey' => 'https://example.com']))->assertUnprocessable();
        $this->get(route('reservations.create', ['inquiry' => $inquiry->id, 'follow' => ['bad']]))->assertUnprocessable();
        $this->assertSame($before, $this->business());
    }

    public function test_direct_legacy_routes_still_return_to_their_original_detail(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $response = $this->actingAs($client, 'web')->post(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(303);
        $r = $inquiry->reservations()->firstOrFail();
        $response->assertRedirect(route('reservations.show', $r));
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $r), ['version' => 1])->assertRedirect(route('reservations.show', $r));
        $this->assertHoldIntegrity();
    }

    public function test_an_unaccepted_quote_expires_without_completing_agreement(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 12345, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $this->travel(2)->days();
        $j = $this->journey($client, $inquiry);
        $this->assertSame('expired', $j['commercial']['quote']['state']);
        $this->assertSame('issue_quote', $j['next_action']['code']);
        $this->assertNull($j['commercial']['agreed_amount']);
        $this->assertSame('current', $j['progress']['steps'][1]['status']);
    }

    public function test_elapsed_proposal_offers_cancellation_instead_of_confirmation(): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        $this->travelTo(Carbon::parse($r->starts_at_utc, 'UTC'));
        $this->assertSame('cancel_expired_proposal', $this->journey($client, $inquiry)['next_action']['code']);
        $p = $this->journey($provider, $inquiry);
        $this->assertSame('reject_expired_proposal', $p['next_action']['code']);
        $this->assertNotContains('confirm', $p['reservation']['actions']);
    }

    public function test_hub_renders_only_authorized_actions_and_retries_keep_original_result(): void
    {
        [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
        $key = $this->holdInput(['_journey' => 1]);
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $r), $key)->assertStatus(303);
        $before = $this->business();
        $this->patch(route('reservations.confirm', $r), $key)->assertStatus(303);
        $this->assertSame($before, $this->business());
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('Confirmar definitivamente mi reserva')->assertSee('Espera la confirmación del cliente');
        $html = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Confirma tu contratación');
        $this->assertSame(1, substr_count($html->getContent(), 'action="'.route('reservations.commit', $r).'"'));
        $this->patch(route('reservations.commit', $r), $this->holdInput(['version' => 99, '_journey' => 1]))->assertStatus(409);
        $this->assertSame($before, $this->business());
        $this->assertHoldIntegrity();
    }

    public function test_the_new_openapi_operation_is_additive_and_uses_the_shared_contract(): void
    {
        $old = json_decode(file_get_contents(base_path('tests/Fixtures/ux-c1-openapi-baseline.json')), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $added = $new['paths']['/inquiries/{inquiry}/journey']['get'];
        unset($new['paths']['/inquiries/{inquiry}/journey']);
        foreach ($this->mobileClientExtensions() as $route) {
            $path = substr($route['uri'], strlen('api/v1'));
            unset($new['paths'][$path][strtolower($route['methods'][0])]);
            if (empty($new['paths'][$path])) {
                unset($new['paths'][$path]);
            }
        }
        $context = &$new['paths']['/reservations/{reservation}/checkout-context']['get']['description'];
        $newContext = ' La confirmación nunca exige un pago bancario verificado. Las nuevas reservas vinculadas a checkout de prueba requieren selección simulada válida y confirmación explícita.';
        $this->assertStringEndsWith($newContext, $context);
        $context = substr($context, 0, -strlen($newContext)).' La confirmación vigente no exige pagos y no cambia.';
        $commit = &$new['paths']['/reservations/{reservation}/commit']['patch']['description'];
        $newCommit = ' Una reserva vinculada al checkout integrado requiere una selección simulada válida antes de confirmar, incluso si posteriormente se desactiva el procesador.';
        $this->assertStringEndsWith($newCommit, $commit);
        $commit = substr($commit, 0, -strlen($newCommit));

        $fingerprint = fn ($value) => hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame($old['paths_sha256'], $fingerprint($new['paths']));
        foreach ($old['components'] as $type => $definitions) {
            foreach ($definitions as $name => $value) {
                $definition = $new['components'][$type][$name];
                if ($type === 'schemas' && $name === 'CheckoutContext') {
                    // Preserve the historical fingerprint of every pre-existing field and rule.
                    $this->assertSame(['$ref' => '#/components/schemas/ConfirmationReadiness'], $definition['properties']['confirmation_readiness']);
                    $this->assertNotContains('confirmation_readiness', $definition['required']);
                    $this->assertNotContains('simulated_checkout', $definition['required']);
                    $this->assertTrue($definition['properties']['simulated_checkout']['properties']['synthetic_only']['const']);
                    unset($definition['properties']['confirmation_readiness'], $definition['properties']['simulated_checkout']);
                }
                $this->assertSame($value, $fingerprint($definition));
            }
        }
        $this->assertSame(array_column(\App\Enums\ReservationStatus::cases(), 'value'), $new['components']['schemas']['JourneyReservation']['properties']['status']['enum']);
        $this->assertSame('getContractingJourney', $added['operationId']);
        $this->assertSame([['bearerAuth' => []]], $added['security']);
        $this->assertSame('inquiry', $added['parameters'][0]['name']);
        $this->assertSame('#/components/schemas/ContractingJourney', $added['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref']);
        $this->assertCurrentApiInventory();
    }

    #[DataProvider('clientPhases')]
    public function test_web_and_mobile_share_the_same_authorized_projection_in_each_phase(string $phase): void
    {
        $this->travelTo(Carbon::parse('2030-01-01 12:00:00', 'UTC'));
        if ($phase === 'pending') {
            [$client, $provider] = [$this->account(RoleName::Client), $this->account(RoleName::Client, RoleName::Provider)];
            $inquiry = $this->inquiry($client, $provider);
        } elseif ($phase === 'legacy') {
            [$client, $provider, $inquiry] = $this->reservationContext();
        } else {
            [$client, $provider, $inquiry, $quote, $r] = $this->pricedProposal();
            $workflow = app(ReservationWorkflow::class);
            if ($phase === 'cancelled' || $phase === 'rejected') {
                $workflow->decide($phase === 'cancelled' ? $client : $provider, $r->id, $phase === 'cancelled' ? 'cancel' : 'reject', $this->holdInput());
            } elseif ($phase !== 'requested') {
                $workflow->decide($provider, $r->id, 'confirm', $this->holdInput());
                if ($phase === 'expired') {
                    $this->travel(900)->seconds();
                } elseif ($phase !== 'held') {
                    $workflow->decide($client, $r->id, 'commit', $this->holdInput());
                    if ($phase !== 'confirmed') {
                        $this->travelTo(Carbon::parse($r->ends_at_utc, 'UTC'));
                        if ($phase !== 'elapsed') {
                            $workflow->decide($provider, $r->id, 'complete', $this->holdInput(['version' => 2]));
                            if (in_array($phase, ['closed', 'published', 'withdrawn'], true)) {
                                app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
                            }
                            if ($phase === 'published' || $phase === 'withdrawn') {
                                $review = app(ReviewWorkflow::class)->publish($client, $r->id, ['rating' => 4, 'comment' => 'Experiencia sintética.'])['review'];
                                if ($phase === 'withdrawn') {
                                    app(ReviewWorkflow::class)->withdraw($client, $review->id);
                                }
                            }
                        }
                    }
                }
            }
        }
        $web = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk();
        $expected = $web->viewData('journey');
        $before = $this->business();
        $this->api($client, $inquiry)->assertOk()->assertExactJson(['data' => $expected]);
        $this->assertSame($before, $this->business());
        $this->assertFalse($expected['payments']['enabled']);
        $this->assertHoldIntegrity();
    }

    public static function clientPhases(): array
    {
        return array_map(fn ($phase) => [$phase], ['pending', 'legacy', 'requested', 'held', 'expired', 'confirmed', 'elapsed', 'completed', 'closed', 'published', 'withdrawn', 'cancelled', 'rejected']);
    }

    public function test_aborted_legacy_attempt_uses_current_commercial_rules_for_a_new_proposal(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $r = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($client, $r->id, 'cancel', ['version' => 1]);
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $j = $this->journey($client, $inquiry);
        $this->assertSame('issue_quote', $j['next_action']['code']);
        $this->assertSame('cancelled', $j['reservation']['status']);
        $this->assertFalse($j['commercial']['can_propose']);
        $this->assertNull($j['commercial']['agreed_amount']);
    }

    public function test_mobile_gate_rechecks_current_roles_and_rejects_nonmobile_tokens(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $token = $client->createToken('web:other', ['client:read'])->plainTextToken;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $headers = $this->bearer($client);
        $client->roles()->detach();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $headers)->assertForbidden();
    }
}
