<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SlotHold
{
    public const DURATION_SECONDS = 900;

    private function __construct(
        public string $id,
        public ReservationProposal $proposal,
        public HoldState $state,
        public int $version,
        public DateTimeImmutable $acceptedAt,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function start(ReservationProposal $proposal, DateTimeImmutable $at): self
    {
        $at = HoldTime::utc($at);

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

        return new self($id, $proposal, HoldState::Held, 1,
            $at, $at->add(new DateInterval('PT900S')), $at);
    }

    /** Reconstitution contract only; does not read storage, insert a hold or acquire a slot. */
    public static function fromStorage(
        string $id,
        ReservationProposal $proposal,
        HoldState $state,
        int $version,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $updatedAt,
    ): self {
        $acceptedAt = HoldTime::utc($acceptedAt);
        $expiresAt = HoldTime::utc($expiresAt);
        $updatedAt = HoldTime::utc($updatedAt);
        $validTime = match ($state) {
            HoldState::Held => $updatedAt == $acceptedAt,
            HoldState::Committed, HoldState::Released => $updatedAt >= $acceptedAt && $updatedAt < $expiresAt,
            HoldState::Expired => $updatedAt >= $expiresAt,
        };
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id)
            || $expiresAt != $acceptedAt->add(new DateInterval('PT900S'))
            || $version !== ($state === HoldState::Held ? 1 : 2) || ! $validTime) {
            throw new InvalidArgumentException('Stored hold violates the synthetic lifecycle.');
        }

        return new self($id, $proposal, $state, $version, $acceptedAt, $expiresAt, $updatedAt);
    }

    public function due(DateTimeImmutable $now): bool
    {
        return $this->state === HoldState::Held && $now >= $this->expiresAt;
    }

    public function transition(HoldState $target, DateTimeImmutable $at): self
    {
        $at = HoldTime::utc($at);
        if ($this->state !== HoldState::Held || $target === HoldState::Held || $at < $this->updatedAt
            || ($target === HoldState::Expired ? $at < $this->expiresAt : $at >= $this->expiresAt)) {
            throw new HoldViolation('invalid_transition');
        }

        return new self($this->id, $this->proposal, $target, $this->version + 1,
            $this->acceptedAt, $this->expiresAt, $at);
    }
}
