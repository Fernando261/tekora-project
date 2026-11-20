<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Payments\PriceVersion;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use Tests\Support\PricingSqlite\PricingValues as P;

/** Companion PricingInSession port. Intentionally has no atomic(). */
final readonly class SharedPricingRepository
{
    private const PRICE = 'id,service_id,provider_profile_id,version,amount_cents,currency,duration_minutes,created_by,idempotency_key,created_at_utc';

    private const QUOTE = 'id,service_inquiry_id,service_id,provider_profile_id,client_user_id,price_version_id,price_version,amount_cents,commission_cents,provider_cents,currency,duration_minutes,commission_bps,provider_bps,policy_version,synthetic,idempotency_key,issued_at_utc,expires_at_utc';

    public function __construct(private SessionSqlExecutor $e) {}

    public function context(int $inquiry): array
    {
        return $this->e->query('SELECT i.*,p.user_id AS provider_user_id FROM b231_inquiries i JOIN b231_providers p ON p.id=i.provider_id WHERE i.id=?', [$inquiry])[0] ?? throw new \LogicException('Unknown inquiry');
    }

    public function service(int $service): array
    {
        return $this->e->query('SELECT s.*,p.user_id FROM b231_services s JOIN b231_providers p ON p.id=s.provider_id WHERE s.id=?', [$service])[0] ?? throw new \LogicException('Unknown service');
    }

    public function latestPrice(int $service): ?PriceVersion
    {
        $r = $this->e->query('SELECT '.self::PRICE.' FROM b231_prices WHERE service_id=? ORDER BY version DESC LIMIT 1', [$service]);

        return $r ? P::hydratePrice($r[0]) : null;
    }

    public function price(string $id): PriceVersion
    {
        $r = $this->e->query('SELECT '.self::PRICE.' FROM b231_prices WHERE id=?', [P::uuid($id)]);

        return P::hydratePrice($r[0] ?? throw new \LogicException('Unknown price'));
    }

    public function appendPrice(PriceVersion $p): void
    {
        $this->e->insert('b231_prices', P::price($p));
    }

    public function appendQuote(SyntheticQuote $q): void
    {
        $this->e->insert('b231_quotes', P::quote($q, $this->price($q->price->id)));
    }

    public function quote(string $id): SyntheticQuote
    {
        $r = $this->e->query('SELECT '.self::QUOTE.' FROM b231_quotes WHERE id=?', [P::uuid($id)])[0] ?? throw new \LogicException('Unknown quote');
        $price = $this->price($r['price_version_id']);
        $q = new SyntheticQuote($r['id'], $r['service_inquiry_id'], $r['service_id'], $r['provider_profile_id'], $r['client_user_id'], $price, $r['idempotency_key'], P::date($r['issued_at_utc']), P::date($r['expires_at_utc']));
        if (P::quote($q, $price) !== $r) {
            throw new \LogicException('Stored quote snapshot differs');
        }

        return $q;
    }

    public function acceptance(string $id): ?QuoteAcceptance
    {
        $r = $this->e->query('SELECT quote_id,client_user_id,accepted_at_utc FROM b231_acceptances WHERE quote_id=?', [P::uuid($id)]);

        return $r ? new QuoteAcceptance($r[0]['quote_id'], P::integer($r[0]['client_user_id']), P::date($r[0]['accepted_at_utc'])) : null;
    }

    public function appendAcceptance(QuoteAcceptance $a): void
    {
        $this->e->insert('b231_acceptances', P::acceptance($a));
    }
}
