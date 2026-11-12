<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;

/**
 * No production binding in B2.1. A persistent adapter must serialize atomic(),
 * roll back structural failures, enforce stable provider user/profile identity,
 * overlap/unique keys/CAS and
 * append history with each transition. Arrays do not establish those SQL guarantees.
 */
interface HoldStore
{
    public function atomic(callable $operation): mixed;

    public function find(string $id): ?SlotHold;

    /** @return list<SlotHold> */
    public function forProvider(int $providerProfileId): array;

    public function forReservation(int $reservationId): ?SlotHold;

    public function forQuote(string $quoteId): ?SlotHold;

    public function receipt(string $scopeKey): ?HoldReceipt;

    public function insert(SlotHold $hold, HoldEvent $event): void;

    public function transition(SlotHold $next, int $expectedVersion, HoldEvent $event): void;

    public function remember(HoldReceipt $receipt): void;

    /** @return list<HoldEvent> */
    public function history(string $holdId): array;

    public function lastClock(): ?DateTimeImmutable;

    public function observeClock(DateTimeImmutable $at): void;

    /** @return list<SlotHold> */
    public function all(): array;
}
