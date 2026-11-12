<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

final readonly class WriteSession
{
    public SharedPricingRepository $pricing;

    public SharedProposalBindingRepository $proposals;

    public SharedHoldHistoryRepository $holds;

    public SharedOccupancyRepository $occupancy;

    public SharedCommandRepository $commands;

    public function __construct(SessionSqlExecutor $executor, public CommandEnvelope $command, public int $at)
    {
        $this->pricing = new SharedPricingRepository($executor);
        $this->proposals = new SharedProposalBindingRepository($executor);
        $this->holds = new SharedHoldHistoryRepository($executor);
        $this->occupancy = new SharedOccupancyRepository($executor);
        $this->commands = new SharedCommandRepository($executor);
    }
}
