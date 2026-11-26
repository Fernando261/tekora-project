<?php

namespace App\Payments;

class SimulatedCheckoutProcessor
{
    public const INSTRUMENT = 'tekora-test-card';

    public function cardOutcome(): string
    {
        return 'test_approved';
    }
}
