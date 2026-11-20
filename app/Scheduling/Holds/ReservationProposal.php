<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use InvalidArgumentException;

/** An initial inquiry alone cannot be supplied to the hold engine. */
final readonly class ReservationProposal
{
    public function __construct(
        public int $reservationId,
        public int $proposalVersion,
        public UtcInterval $interval,
        public AcceptedQuoteReference $quote,
    ) {
        if ($reservationId < 1 || $proposalVersion < 1) {
            throw new InvalidArgumentException('A concrete reservation and proposal version are required.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->reservationId, $this->proposalVersion,
            HoldTime::text($this->interval->start), HoldTime::text($this->interval->end),
            $this->quote->id, $this->quote->inquiryId, $this->quote->serviceId,
            $this->quote->providerProfileId, $this->quote->providerUserId, $this->quote->clientUserId,
        ], JSON_THROW_ON_ERROR));
    }
}
