<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use App\Payments\CommercialAmount;
use App\Payments\PriceVersion;
use App\Payments\PricingConflict;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use DateTimeImmutable;
use DateTimeZone;

/** Validate both DTO input and stored representations without lossy casts. */
final class PricingValues
{
    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value)) {
            throw new PricingConflict('A canonical lowercase UUID is required.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw new PricingConflict('A positive stored integer is required.');
        }

        return $value;
    }

    public static function text(DateTimeImmutable $value): string
    {
        $text = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        self::date($text);

        return $text;
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/', $value)
            || $value < '2000-01-01 00:00:00.000000' || $value > '2100-12-31 23:59:59.999999') {
            throw new PricingConflict('Invalid stored UTC representation.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new PricingConflict('Invalid UTC calendar value.');
        }

        return $date;
    }

    public static function price(PriceVersion $price): array
    {
        $amount = CommercialAmount::fromCents($price->amount->totalCents);
        if ((array) $amount !== (array) $price->amount || $price->version > 1_000_000_000) {
            throw new PricingConflict('Inconsistent commercial snapshot.');
        }

        return [
            'id' => self::uuid($price->id), 'service_id' => self::integer($price->serviceId),
            'provider_profile_id' => self::integer($price->providerProfileId), 'version' => self::integer($price->version),
            'amount_cents' => $amount->totalCents, 'currency' => $amount->currency, 'duration_minutes' => $amount->durationMinutes,
            'created_by' => self::integer($price->createdBy), 'idempotency_key' => self::uuid($price->idempotencyKey),
            'created_at_utc' => self::text($price->createdAt),
        ];
    }

    public static function hydratePrice(array $row): PriceVersion
    {
        $amount = CommercialAmount::fromCents(self::integer($row['amount_cents']));
        if ($row['currency'] !== $amount->currency || $row['duration_minutes'] !== 60) {
            throw new PricingConflict('Invalid price policy in storage.');
        }
        $price = new PriceVersion(self::uuid($row['id']), self::integer($row['service_id']), self::integer($row['provider_profile_id']),
            self::integer($row['version']), $amount, self::integer($row['created_by']), self::uuid($row['idempotency_key']), self::date($row['created_at_utc']));
        self::price($price);

        return $price;
    }

    public static function quote(SyntheticQuote $quote, PriceVersion $registered): array
    {
        if (self::price($quote->price) !== self::price($registered) || $quote->serviceId !== $registered->serviceId
            || $quote->providerProfileId !== $registered->providerProfileId || $quote->synthetic !== true
            || $quote->issuedAt < $registered->createdAt || $quote->expiresAt < $quote->issuedAt->modify('+1 second')
            || $quote->expiresAt > $quote->issuedAt->modify('+1 day')) {
            throw new PricingConflict('The quote does not match its registered immutable price.');
        }
        $amount = $registered->amount;

        return [
            'id' => self::uuid($quote->id), 'service_inquiry_id' => self::integer($quote->inquiryId),
            'service_id' => self::integer($quote->serviceId), 'provider_profile_id' => self::integer($quote->providerProfileId),
            'client_user_id' => self::integer($quote->clientId), 'price_version_id' => $registered->id,
            'price_version' => $registered->version, 'amount_cents' => $amount->totalCents,
            'commission_cents' => $amount->commissionCents, 'provider_cents' => $amount->providerCents,
            'currency' => $amount->currency, 'duration_minutes' => 60, 'commission_bps' => 1500, 'provider_bps' => 8500,
            'policy_version' => $amount->policyVersion, 'synthetic' => 1, 'idempotency_key' => self::uuid($quote->idempotencyKey),
            'issued_at_utc' => self::text($quote->issuedAt), 'expires_at_utc' => self::text($quote->expiresAt),
        ];
    }

    public static function acceptance(QuoteAcceptance $acceptance): array
    {
        return ['quote_id' => self::uuid($acceptance->quoteId), 'client_user_id' => self::integer($acceptance->clientId),
            'accepted_at_utc' => self::text($acceptance->acceptedAt)];
    }
}
