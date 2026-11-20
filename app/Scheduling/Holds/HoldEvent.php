<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;

final readonly class HoldEvent
{
    public DateTimeImmutable $at;

    public function __construct(
        public string $holdId,
        public int $version,
        public ?HoldState $from,
        public HoldState $to,
        public HoldActor $actor,
        DateTimeImmutable $at,
    ) {
        $this->at = HoldTime::utc($at);
    }

    public function authorizes(ReservationProposal $proposal): bool
    {
        return match ($this->to) {
            HoldState::Held => $this->actor->mayAccept($proposal),
            HoldState::Released => $this->actor->mayRelease($proposal),
            HoldState::Committed, HoldState::Expired => $this->actor->kind === 'system',
        };
    }
}
