<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;
use InvalidArgumentException;

final class SyntheticHoldEngine
{
    public function __construct(private readonly HoldStore $store, private readonly HoldClock $clock) {}

    public function accept(ReservationProposal $proposal, HoldActor $actor, string $key): SlotHold
    {
        if (! $actor->mayAccept($proposal)) {
            throw new HoldViolation('unauthorized');
        }
        $scope = $this->scope($actor, $key);
        $fingerprint = hash('sha256', 'accept:'.$proposal->fingerprint());

        return $this->execute(function (DateTimeImmutable $now) use ($proposal, $actor, $scope, $fingerprint) {
            $replay = $this->replay($scope, $fingerprint);
            if ($replay) {
                return $this->expireOne($replay, $now);
            }
            $now = $this->readClock();
            foreach ($this->store->forProvider($proposal->quote->providerProfileId) as $hold) {
                $this->expireOne($hold, $now);
            }
            if ($this->store->forReservation($proposal->reservationId)) {
                return new HoldViolation('reservation_already_bound');
            }
            if ($this->store->forQuote($proposal->quote->id)) {
                return new HoldViolation('quote_already_bound');
            }
            foreach ($this->store->forProvider($proposal->quote->providerProfileId) as $hold) {
                if ($hold->state->blocks() && $hold->proposal->interval->overlaps($proposal->interval)) {
                    return new HoldViolation('slot_conflict');
                }
            }
            $recordedAt = $this->readClock();
            $hold = SlotHold::start($proposal, $recordedAt);
            $this->store->insert($hold, new HoldEvent($hold->id, 1, null, HoldState::Held, $actor, $recordedAt));
            $this->store->remember(new HoldReceipt($scope, $fingerprint, $hold->id));

            return $hold;
        });
    }

    public function release(string $id, HoldActor $actor, int $expectedVersion, string $key): SlotHold
    {
        return $this->decide($id, $actor, $expectedVersion, $key, HoldState::Released);
    }

    /** Conceptual conversion only: no payment verification or real reservation write. */
    public function commit(string $id, HoldActor $actor, int $expectedVersion, string $key): SlotHold
    {
        return $this->decide($id, $actor, $expectedVersion, $key, HoldState::Committed);
    }

    /** @return list<SlotHold> */
    public function expireDue(HoldActor $actor): array
    {
        if ($actor->kind !== 'system') {
            throw new HoldViolation('unauthorized');
        }

        return $this->execute(function (DateTimeImmutable $now) {
            $expired = [];
            foreach ($this->store->all() as $hold) {
                if ($hold->due($now)) {
                    $expired[] = $this->expireOne($hold, $now);
                }
            }

            return $expired;
        });
    }

    private function decide(string $id, HoldActor $actor, int $version, string $key, HoldState $target): SlotHold
    {
        $hold = $this->store->find($id) ?? throw new HoldViolation('not_found');
        if ($target === HoldState::Committed ? $actor->kind !== 'system' : ! $actor->mayRelease($hold->proposal)) {
            throw new HoldViolation('unauthorized');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('A positive hold version is required.');
        }
        $scope = $this->scope($actor, $key);
        $fingerprint = hash('sha256', $target->value.':'.$id.':'.$version);

        return $this->execute(function (DateTimeImmutable $now) use ($id, $actor, $version, $scope, $fingerprint, $target) {
            $current = $this->store->find($id) ?? throw new HoldViolation('not_found');
            $replay = $this->replay($scope, $fingerprint);
            if ($replay) {
                return $replay;
            }
            $now = $this->readClock();
            $current = $this->expireOne($current, $now);
            if ($current->state !== HoldState::Held) {
                return new HoldViolation('invalid_state');
            }
            if ($current->version !== $version) {
                return new HoldViolation('version_conflict');
            }
            $next = $current->transition($target, $now);
            $this->store->transition($next, $version, new HoldEvent($id, $next->version, $current->state, $target, $actor, $now));
            $this->store->remember(new HoldReceipt($scope, $fingerprint, $id));

            return $next;
        });
    }

    private function expireOne(SlotHold $hold, DateTimeImmutable $now): SlotHold
    {
        if (! $hold->due($now)) {
            return $hold;
        }
        $next = $hold->transition(HoldState::Expired, $now);
        $this->store->transition($next, $hold->version,
            new HoldEvent($hold->id, $next->version, $hold->state, HoldState::Expired, HoldActor::system(), $now));

        return $next;
    }

    private function replay(string $scope, string $fingerprint): ?SlotHold
    {
        $receipt = $this->store->receipt($scope);
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->fingerprint, $fingerprint)) {
            throw new HoldViolation('idempotency_conflict');
        }

        return $this->store->find($receipt->holdId) ?? throw new \LogicException('Receipt without its hold.');
    }

    private function scope(HoldActor $actor, string $key): string
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $key)) {
            throw new InvalidArgumentException('A canonical UUID intention is required.');
        }

        return $actor->scope().':'.strtolower($key);
    }

    private function execute(callable $operation): mixed
    {
        $result = $this->store->atomic(function () use ($operation) {
            return $operation($this->readClock());
        });
        // Persist legitimate expiry even when the requested late action is rejected.
        if ($result instanceof HoldViolation) {
            throw $result;
        }

        return $result;
    }

    private function readClock(): DateTimeImmutable
    {
        $now = HoldTime::utc($this->clock->now());
        if ($this->store->lastClock() && $now < $this->store->lastClock()) {
            throw new HoldViolation('clock_regressed');
        }
        $this->store->observeClock($now);

        return $now;
    }
}
