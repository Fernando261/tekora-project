<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use InvalidArgumentException;

/** Trusted synthetic input, NOT proof of B1 acceptance or authenticated ownership. */
final readonly class AcceptedQuoteReference
{
    public function __construct(
        public string $id,
        public int $inquiryId,
        public int $serviceId,
        public int $providerProfileId,
        public int $providerUserId,
        public int $clientUserId,
    ) {
        if ($id === '' || strlen($id) > 128 || preg_match('/[^a-zA-Z0-9_-]/', $id)
            || min($inquiryId, $serviceId, $providerProfileId, $providerUserId, $clientUserId) < 1
            || $providerUserId === $clientUserId) {
            throw new InvalidArgumentException('Invalid synthetic quote reference.');
        }
    }
}
