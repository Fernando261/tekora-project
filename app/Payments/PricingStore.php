<?php

namespace App\Payments;

/**
 * No production binding or persistent implementation in B1.
 * Implementations must serialize atomic(), roll back failures and enforce the
 * unique keys / append-only contract at the storage boundary, not just in PHP.
 */
interface PricingStore
{
    public function atomic(callable $operation): mixed;

    public function latestPrice(int $serviceId): ?PriceVersion;

    public function priceByKey(int $serviceId, string $key): ?PriceVersion;

    public function appendPrice(PriceVersion $price): void;

    public function quote(string $id): ?SyntheticQuote;

    public function quoteByKey(int $clientId, string $key): ?SyntheticQuote;

    public function appendQuote(SyntheticQuote $quote): void;

    public function acceptance(string $quoteId): ?QuoteAcceptance;

    public function appendAcceptance(QuoteAcceptance $acceptance): void;
}
