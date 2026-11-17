<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class QuoteAcceptance
{
    public function __construct(
        public string $quoteId,
        public int $clientId,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
