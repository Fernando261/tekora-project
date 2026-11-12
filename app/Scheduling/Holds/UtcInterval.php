<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class UtcInterval
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $this->start = HoldTime::utc($start);
        $this->end = HoldTime::utc($end);
        if ($this->end != $this->start->add(new DateInterval('PT3600S'))) {
            throw new InvalidArgumentException('The synthetic service interval must be exactly 60 minutes.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }
}
