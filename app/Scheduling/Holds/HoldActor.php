<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use InvalidArgumentException;

/** Adapter-issued authority assertion for the synthetic model; not an HTTP credential. */
final readonly class HoldActor
{
    private function __construct(public string $kind, public ?int $userId) {}

    public static function provider(int $userId): self
    {
        return self::user('provider', $userId);
    }

    public static function client(int $userId): self
    {
        return self::user('client', $userId);
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    private static function user(string $kind, int $id): self
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A positive actor identity is required.');
        }

        return new self($kind, $id);
    }

    public function mayAccept(ReservationProposal $proposal): bool
    {
        return $this->kind === 'provider' && $this->userId === $proposal->quote->providerUserId;
    }

    public function mayRelease(ReservationProposal $proposal): bool
    {
        return $this->mayAccept($proposal)
            || ($this->kind === 'client' && $this->userId === $proposal->quote->clientUserId);
    }

    public function scope(): string
    {
        return $this->kind.':'.($this->userId ?? 'system');
    }
}
