<?php

namespace App\Payments;

use DateTimeImmutable;

/** A read snapshot, never a payment attempt, authorization or financial receipt. */
final readonly class QuoteSnapshot
{
    public function __construct(
        public SyntheticQuote $quote,
        public ?QuoteAcceptance $acceptance,
        public DateTimeImmutable $serverNow,
    ) {}

    public function acceptanceState(): string
    {
        // Expiration bounds first acceptance, not the lifetime of an acceptance.
        return $this->acceptance ? 'accepted' : ($this->serverNow >= $this->quote->expiresAt ? 'expired' : 'pending');
    }
}
