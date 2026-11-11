<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Payments\CommercialAmount;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InMemoryPricingStore;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

#[Group('payments-b1')]
class PaymentsPricingQuotesTest extends TestCase
{
    use ReservationFixtures;

    private InMemoryPricingStore $prices;

    private ServicePricing $pricing;

    private SyntheticQuotes $quotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prices = new InMemoryPricingStore;
        $this->pricing = new ServicePricing($this->prices);
        $this->quotes = new SyntheticQuotes($this->prices);
    }

    private function priceInput(array $changes = []): array
    {
        return $changes + ['amount_cents' => 100_000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()];
    }

    private function quoteInput(): array
    {
        return ['idempotency_key' => (string) Str::uuid()];
    }

    private function pricedContext(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $price = $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput());

        return [$client, $provider, $inquiry, $price];
    }

    public function test_owner_can_append_price_versions_without_rewriting_a_service(): void
    {
        [$client, $provider, $inquiry, $first] = $this->pricedContext();
        $before = $inquiry->service->getRawOriginal();
        $second = $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput(['expected_version' => '1', 'amount_cents' => '200000']));
        $this->assertSame([1, 2], [$first->version, $second->version]);
        $this->assertSame([100_000, 200_000], [$first->amount->totalCents, $second->amount->totalCents]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($provider->id, $second->createdBy);
        $this->assertSame($before, $inquiry->service->fresh()->getRawOriginal());
    }

    public function test_price_retry_returns_original_even_after_a_later_version(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $input = $this->priceInput();
        $first = $this->pricing->publish($provider, $inquiry->service_id, $input);
        $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput(['expected_version' => 1]));
        $input['idempotency_key'] = strtoupper($input['idempotency_key']);
        $this->assertSame($first, $this->pricing->publish($provider, $inquiry->service_id, $input));
        $this->assertSame(2, $this->prices->latestPrice($inquiry->service_id)->version);
    }

    public function test_stale_version_and_key_reuse_with_different_content_are_rejected(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $input = $this->priceInput();
        $first = $this->pricing->publish($provider, $inquiry->service_id, $input);
        foreach ([$this->priceInput(), array_replace($input, ['amount_cents' => 20]), array_replace($input, ['expected_version' => 1])] as $bad) {
            try {
                $this->pricing->publish($provider, $inquiry->service_id, $bad);
                $this->fail('Debe rechazar el conflicto sin agregar versiones.');
            } catch (PricingConflict) {
                $this->assertSame($first, $this->prices->latestPrice($inquiry->service_id));
            }
        }
    }

    public function test_only_current_owner_with_provider_permissions_can_publish_or_replay(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $input = $this->priceInput();
        $this->pricing->publish($provider, $inquiry->service_id, $input);
        foreach ([$client, $this->account(RoleName::Provider), $this->account(RoleName::Admin)] as $actor) {
            try {
                $this->pricing->publish($actor, $inquiry->service_id, $input);
                $this->fail('No debe modificar ni recuperar una modificación ajena.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $this->prices->latestPrice($inquiry->service_id)->version);
            }
        }
        $provider->roles()->detach();
        $this->expectException(AuthorizationException::class);
        $this->pricing->publish($provider, $inquiry->service_id, $input);
    }

    public function test_invalid_price_fields_and_versions_do_not_create_a_price(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $inputs = [];
        foreach ([0, -1, CommercialAmount::MAX_CENTS + 1, 1.0, '1e2', '01', null] as $amount) {
            $inputs[] = $this->priceInput(['amount_cents' => $amount]);
        }
        foreach ([-1, true, 1.0, '00', '1.0', '9999999999', null] as $version) {
            $inputs[] = $this->priceInput(['expected_version' => $version]);
        }
        foreach (['currency', 'duration_minutes', 'commission_cents', 'provider_profile_id', 'created_at', 'policy_version'] as $field) {
            $inputs[] = $this->priceInput() + [$field => null];
        }
        $inputs[] = $this->priceInput(['idempotency_key' => 'invalid']);
        foreach ($inputs as $input) {
            try {
                $this->pricing->publish($provider, $inquiry->service_id, $input);
                $this->fail('La entrada no canónica no debe crear un precio.');
            } catch (ValidationException) {
                $this->assertNull($this->prices->latestPrice($inquiry->service_id));
            }
        }
    }

    public function test_server_issues_exact_snapshot_and_does_not_change_it_when_price_changes(): void
    {
        [$client, $provider, $inquiry, $price] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $snapshot = serialize($quote);
        $this->assertTrue($quote->synthetic);
        $this->assertSame($price, $quote->price);
        $this->assertSame([$client->id, $inquiry->id, $inquiry->service_id, $inquiry->service->provider_profile_id],
            [$quote->clientId, $quote->inquiryId, $quote->serviceId, $quote->providerProfileId]);
        $this->assertSame(1800, $quote->expiresAt->getTimestamp() - $quote->issuedAt->getTimestamp());
        $this->assertSame([100_000, 15_000, 85_000], [$quote->price->amount->totalCents, $quote->price->amount->commissionCents, $quote->price->amount->providerCents]);
        $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput(['expected_version' => 1, 'amount_cents' => 200_000]));
        $acceptance = $this->quotes->accept($client, $quote->id);
        $this->assertSame($snapshot, serialize($this->quotes->view($client, $quote->id)));
        $this->assertSame($quote->id, $acceptance->quoteId);
        $new = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->assertSame(200_000, $new->price->amount->totalCents);
    }

    public function test_nested_snapshot_is_immutable_after_acceptance(): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->quotes->accept($client, $quote->id);
        $this->expectException(\Error::class);
        $quote->price->amount->providerCents = 0;
    }

    public static function forbiddenQuoteFields(): array
    {
        return array_map(fn ($field) => [$field], ['amount_cents', 'commission_cents', 'provider_cents', 'currency', 'duration_minutes',
            'client_id', 'provider_profile_id', 'price_version', 'expires_at', 'accepted_at', 'tax_cents', 'processor_cost_cents']);
    }

    #[DataProvider('forbiddenQuoteFields')]
    public function test_client_cannot_supply_financial_or_identity_fields(string $field): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $this->expectException(ValidationException::class);
        $this->quotes->issue($client, $inquiry->id, $this->quoteInput() + [$field => null]);
    }

    public static function expirationBoundaries(): array
    {
        return [[59, true], [60, false], [61, false]];
    }

    #[DataProvider('expirationBoundaries')]
    public function test_expiration_is_checked_by_server_with_an_exclusive_deadline(int $seconds, bool $valid): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $quotes = new SyntheticQuotes($this->prices, 60);
        $quote = $quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->travel($seconds)->seconds();
        if (! $valid) {
            $this->expectException(PricingConflict::class);
        }
        $acceptance = $quotes->accept($client, $quote->id);
        $this->assertSame($client->id, $acceptance->clientId);
    }

    public function test_retries_never_extend_expiration_or_repeat_acceptance(): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $input = $this->quoteInput();
        $quote = $this->quotes->issue($client, $inquiry->id, $input);
        $acceptance = $this->quotes->accept($client, $quote->id);
        $this->travel(1)->hours();
        $input['idempotency_key'] = strtoupper($input['idempotency_key']);
        $this->assertSame($quote, $this->quotes->issue($client, $inquiry->id, $input));
        $this->assertSame($acceptance, $this->quotes->accept($client, $quote->id));
        $this->assertLessThan(now('UTC')->toDateTimeImmutable(), $quote->expiresAt);
    }

    public function test_expired_unaccepted_retry_does_not_renew_a_quote(): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $input = $this->quoteInput();
        $quote = $this->quotes->issue($client, $inquiry->id, $input);
        $this->travel(1)->hours();
        $this->assertSame($quote, $this->quotes->issue($client, $inquiry->id, $input));
        $this->expectException(PricingConflict::class);
        $this->quotes->accept($client, $quote->id);
    }

    public function test_quote_key_cannot_be_reused_for_another_inquiry(): void
    {
        [$client, $provider, $inquiry] = $this->pricedContext();
        $other = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $input = $this->quoteInput();
        $this->quotes->issue($client, $inquiry->id, $input);
        $this->expectException(PricingConflict::class);
        $this->quotes->issue($client, $other->id, $input);
    }

    public function test_identical_uuids_from_different_clients_do_not_share_a_quote(): void
    {
        [$client, $provider, $inquiry] = $this->pricedContext();
        $otherClient = $this->account(RoleName::Client);
        $otherInquiry = $this->inquiry($otherClient, $provider, InquiryStatus::Accepted);
        $this->pricing->publish($provider, $otherInquiry->service_id, $this->priceInput());
        $input = $this->quoteInput();
        $first = $this->quotes->issue($client, $inquiry->id, $input);
        $second = $this->quotes->issue($otherClient, $otherInquiry->id, $input);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($client->id, $first->clientId);
        $this->assertSame($otherClient->id, $second->clientId);
    }

    public static function invalidLifetimes(): array
    {
        return [[0], [-1], [86401]];
    }

    #[DataProvider('invalidLifetimes')]
    public function test_synthetic_quote_lifetime_must_be_positive_and_bounded(int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SyntheticQuotes($this->prices, $seconds);
    }

    public function test_other_customer_cannot_issue_for_another_clients_inquiry(): void
    {
        [, , $inquiry] = $this->pricedContext();
        $this->expectException(ModelNotFoundException::class);
        $this->quotes->issue($this->account(RoleName::Client), $inquiry->id, $this->quoteInput());
    }

    public function test_other_customer_provider_and_admin_cannot_read_or_accept_quote(): void
    {
        [$client, $provider, $inquiry] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        foreach ([$provider, $this->account(RoleName::Client), $this->account(RoleName::Admin)] as $actor) {
            foreach (['view', 'accept'] as $method) {
                try {
                    $this->quotes->$method($actor, $quote->id);
                    $this->fail('No debe acceder a una cotización ajena.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                    $this->assertNull($this->prices->acceptance($quote->id));
                }
            }
        }
    }

    public function test_current_client_permissions_are_required_even_for_accepted_replay(): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->quotes->accept($client, $quote->id);
        $client->roles()->detach();
        $this->expectException(AuthorizationException::class);
        $this->quotes->accept($client, $quote->id);
    }

    public function test_a_service_without_a_price_cannot_be_quoted(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $this->expectException(PricingConflict::class);
        $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
    }

    public function test_new_quote_requires_an_accepted_inquiry(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput());
        $this->expectException(PricingConflict::class);
        $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
    }

    public function test_closed_inquiry_prevents_new_acceptance_but_retains_private_quote_history(): void
    {
        [$client, $provider, $inquiry] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $this->assertSame($quote, $this->quotes->view($client, $quote->id));
        $this->expectException(PricingConflict::class);
        $this->quotes->accept($client, $quote->id);
    }

    public function test_acceptance_does_not_accept_payload_overrides(): void
    {
        [$client, , $inquiry] = $this->pricedContext();
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->expectException(ValidationException::class);
        $this->quotes->accept($client, $quote->id, ['amount_cents' => 1]);
    }

    public function test_store_rolls_back_an_interrupted_append(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        try {
            $this->prices->atomic(function () use ($provider, $inquiry) {
                $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput());
                throw new \RuntimeException('synthetic failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic failure', $exception->getMessage());
        }
        $this->assertNull($this->prices->latestPrice($inquiry->service_id));
    }

    public function test_quote_workflow_does_not_write_operational_tables_or_confirm_reservations(): void
    {
        [$client, $provider, $inquiry] = $this->pricedContext();
        $reservation = $this->proposeReservation($inquiry);
        $tables = ['services', 'service_inquiries', 'service_reservations', 'reservation_events', 'inquiry_notifications', 'activity_notifications'];
        $before = $this->reservationRows($tables);
        $quote = $this->quotes->issue($client, $inquiry->id, $this->quoteInput());
        $this->quotes->accept($client, $quote->id);
        $this->pricing->publish($provider, $inquiry->service_id, $this->priceInput(['expected_version' => 1]));
        $this->assertSame($before, $this->reservationRows($tables));
        $this->assertSame('requested', $reservation->fresh()->status->value);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->assertSame('confirmed', $reservation->fresh()->status->value);
    }

    public function test_b1_has_no_production_store_binding_or_financial_routes(): void
    {
        $this->assertFalse(app()->bound(PricingStore::class));
        $this->assertCount(37, glob(database_path('migrations/*.php')));
        $api = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($api['paths'] as $path) {
            $count += count(array_intersect(array_keys($path), ['get', 'post', 'put', 'patch', 'delete']));
        }
        $this->assertSame(21, $count);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('App\\Payments\\', $route->getActionName());
        }
    }
}
