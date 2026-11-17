<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class SyntheticQuote
{
    public const SYNTHETIC = true;

    public bool $synthetic;

    public function __construct(
        public string $id,
        public int $inquiryId,
        public int $serviceId,
        public int $providerProfileId,
        public int $clientId,
        public PriceVersion $price,
        public string $idempotencyKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->synthetic = self::SYNTHETIC;
    }
}
