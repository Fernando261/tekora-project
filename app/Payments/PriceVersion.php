<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class PriceVersion
{
    public function __construct(
        public string $id,
        public int $serviceId,
        public int $providerProfileId,
        public int $version,
        public CommercialAmount $amount,
        public int $createdBy,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
    ) {}
}
