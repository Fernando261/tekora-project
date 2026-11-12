<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

enum HoldState: string
{
    case Held = 'held';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';

    public function blocks(): bool
    {
        return $this === self::Held || $this === self::Committed;
    }
}
