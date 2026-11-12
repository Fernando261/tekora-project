<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldReceipt;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\HoldStore;
use App\Scheduling\Holds\HoldViolation;
use App\Scheduling\Holds\SlotHold;
use Closure;
use DateTimeImmutable;
use LogicException;
use Throwable;

/** Single-process test double. Reentry is rejected, not a concurrency guarantee. */
final class InMemorySlotHoldStore implements HoldStore
{
    private array $holds = [];

    private array $events = [];

    private array $receipts = [];

    private ?DateTimeImmutable $clock = null;

    private bool $active = false;

    public ?Closure $beforeCommit = null;

    public function atomic(callable $operation): mixed
    {
        if ($this->active) {
            throw new LogicException('The in-memory double does not support reentry or concurrent transactions.');
        }
        $before = [$this->holds, $this->events, $this->receipts, $this->clock];
        $this->active = true;
        try {
            $result = $operation();
            if ($this->beforeCommit) {
                ($this->beforeCommit)();
            }

            return $result;
        } catch (Throwable $error) {
            [$this->holds, $this->events, $this->receipts, $this->clock] = $before;
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    public function find(string $id): ?SlotHold
    {
        return $this->holds[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->holds);
    }

    public function forProvider(int $providerProfileId): array
    {
        return array_values(array_filter($this->holds,
            fn (SlotHold $hold) => $hold->proposal->quote->providerProfileId === $providerProfileId));
    }

    public function forReservation(int $reservationId): ?SlotHold
    {
        foreach ($this->holds as $hold) {
            if ($hold->proposal->reservationId === $reservationId) {
                return $hold;
            }
        }

        return null;
    }

    public function forQuote(string $quoteId): ?SlotHold
    {
        foreach ($this->holds as $hold) {
            if ($hold->proposal->quote->id === $quoteId) {
                return $hold;
            }
        }

        return null;
    }

    public function receipt(string $scopeKey): ?HoldReceipt
    {
        return $this->receipts[$scopeKey] ?? null;
    }

    public function insert(SlotHold $hold, HoldEvent $event): void
    {
        $this->requireTransaction();
        if (isset($this->holds[$hold->id]) || $this->forReservation($hold->proposal->reservationId)
            || $this->forQuote($hold->proposal->quote->id) || $hold->version !== 1 || $hold->state !== HoldState::Held
            || $event->from !== null || $hold->acceptedAt != $hold->updatedAt) {
            throw new HoldViolation('invalid_insert');
        }
        $this->checkEvent($hold, $event);
        $this->checkProviderIdentity($hold);
        $this->checkOverlap($hold);
        $this->holds[$hold->id] = $hold;
        $this->events[$hold->id] = [$event];
    }

    public function transition(SlotHold $next, int $expectedVersion, HoldEvent $event): void
    {
        $this->requireTransaction();
        $current = $this->find($next->id) ?? throw new HoldViolation('not_found');
        if ($current->version !== $expectedVersion) {
            throw new HoldViolation('version_conflict');
        }
        if ($current->proposal->fingerprint() !== $next->proposal->fingerprint()
            || $current->acceptedAt != $next->acceptedAt || $current->expiresAt != $next->expiresAt
            || $next->version !== $current->version + 1 || $event->from !== $current->state) {
            throw new HoldViolation('immutable_binding');
        }
        $valid = $current->transition($next->state, $next->updatedAt);
        if ($valid->version !== $next->version) {
            throw new HoldViolation('invalid_transition');
        }
        $this->checkEvent($next, $event);
        $this->checkOverlap($next);
        $this->holds[$next->id] = $next;
        $this->events[$next->id][] = $event;
    }

    public function remember(HoldReceipt $receipt): void
    {
        $this->requireTransaction();
        if (isset($this->receipts[$receipt->scopeKey]) || ! $this->find($receipt->holdId)) {
            throw new HoldViolation('invalid_receipt');
        }
        $this->receipts[$receipt->scopeKey] = $receipt;
    }

    public function history(string $holdId): array
    {
        return $this->events[$holdId] ?? [];
    }

    public function lastClock(): ?DateTimeImmutable
    {
        return $this->clock;
    }

    public function observeClock(DateTimeImmutable $at): void
    {
        $this->requireTransaction();
        if ($this->clock && $at < $this->clock) {
            throw new HoldViolation('clock_regressed');
        }
        $this->clock = $at;
    }

    private function checkEvent(SlotHold $hold, HoldEvent $event): void
    {
        if ($event->holdId !== $hold->id || $event->version !== $hold->version || $event->to !== $hold->state
            || $event->at != $hold->updatedAt || ! $this->clock || $hold->updatedAt != $this->clock
            || ! $event->authorizes($hold->proposal)) {
            throw new HoldViolation('invalid_history');
        }
    }

    private function checkOverlap(SlotHold $candidate): void
    {
        if (! $candidate->state->blocks()) {
            return;
        }
        foreach ($this->forProvider($candidate->proposal->quote->providerProfileId) as $other) {
            if ($other->id !== $candidate->id && $other->state->blocks()
                && $other->proposal->interval->overlaps($candidate->proposal->interval)) {
                throw new HoldViolation('slot_conflict');
            }
        }
    }

    private function checkProviderIdentity(SlotHold $candidate): void
    {
        $identity = $candidate->proposal->quote;
        foreach ($this->holds as $hold) {
            $known = $hold->proposal->quote;
            if (($known->providerProfileId === $identity->providerProfileId && $known->providerUserId !== $identity->providerUserId)
                || ($known->providerUserId === $identity->providerUserId && $known->providerProfileId !== $identity->providerProfileId)) {
                throw new HoldViolation('provider_identity_conflict');
            }
        }
    }

    private function requireTransaction(): void
    {
        if (! $this->active) {
            throw new LogicException('Writes require the synthetic atomic boundary.');
        }
    }
}
