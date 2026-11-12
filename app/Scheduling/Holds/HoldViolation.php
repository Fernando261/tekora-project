<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DomainException;

final class HoldViolation extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
