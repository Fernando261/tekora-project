<?php

namespace Tests\Support;

use App\Payments\PriceVersion;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use Throwable;

/** Synthetic single-process store. Not evidence of persistent SQLite concurrency. */
final class InMemoryPricingStore implements PricingStore
{
    private array $prices = [];

    private array $quotes = [];

    private array $acceptances = [];

    public function atomic(callable $operation): mixed
    {
        $before = [$this->prices, $this->quotes, $this->acceptances];
        try {
            return $operation();
        } catch (Throwable $exception) {
            [$this->prices, $this->quotes, $this->acceptances] = $before;
            throw $exception;
        }
    }

    public function latestPrice(int $serviceId): ?PriceVersion
    {
        $matches = array_filter($this->prices, fn ($price) => $price->serviceId === $serviceId);

        return $matches ? end($matches) : null;
    }

    public function priceByKey(int $serviceId, string $key): ?PriceVersion
    {
        foreach ($this->prices as $price) {
            if ($price->serviceId === $serviceId && $price->idempotencyKey === $key) {
                return $price;
            }
        }

        return null;
    }

    public function appendPrice(PriceVersion $price): void
    {
        if (isset($this->prices[$price->id]) || $this->priceByKey($price->serviceId, $price->idempotencyKey)
            || $price->version !== ($this->latestPrice($price->serviceId)?->version ?? 0) + 1) {
            throw new PricingConflict('Versión o intención de precio duplicada.');
        }
        $this->prices[$price->id] = $price;
    }

    public function quote(string $id): ?SyntheticQuote
    {
        return $this->quotes[$id] ?? null;
    }

    public function quoteByKey(int $clientId, string $key): ?SyntheticQuote
    {
        foreach ($this->quotes as $quote) {
            if ($quote->clientId === $clientId && $quote->idempotencyKey === $key) {
                return $quote;
            }
        }

        return null;
    }

    public function appendQuote(SyntheticQuote $quote): void
    {
        if (isset($this->quotes[$quote->id]) || $this->quoteByKey($quote->clientId, $quote->idempotencyKey)
            || ($this->prices[$quote->price->id] ?? null) !== $quote->price
            || $quote->price->serviceId !== $quote->serviceId || $quote->price->providerProfileId !== $quote->providerProfileId
            || $quote->expiresAt <= $quote->issuedAt) {
            throw new PricingConflict('Cotización duplicada o sin versión de precio registrada.');
        }
        $this->quotes[$quote->id] = $quote;
    }

    public function acceptance(string $quoteId): ?QuoteAcceptance
    {
        return $this->acceptances[$quoteId] ?? null;
    }

    public function appendAcceptance(QuoteAcceptance $acceptance): void
    {
        $quote = $this->quote($acceptance->quoteId);
        if (! $quote || isset($this->acceptances[$acceptance->quoteId]) || $quote->clientId !== $acceptance->clientId
            || $acceptance->acceptedAt < $quote->issuedAt || $acceptance->acceptedAt >= $quote->expiresAt) {
            throw new PricingConflict('Aceptación duplicada o incompatible con su cotización.');
        }
        $this->acceptances[$acceptance->quoteId] = $acceptance;
    }
}
