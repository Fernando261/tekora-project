<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Scheduling\Holds\SlotHold;

final readonly class SharedOccupancyRepository
{
    public function __construct(private SessionSqlExecutor $e) {}

    public function conflicts(int $provider, int $start, int $end): bool
    {
        return $this->e->query("SELECT id FROM b231_claims WHERE provider_id=? AND phase IN ('held','reserved') AND starts_at_us<? AND ends_at_us>?", [$provider, $end, $start]) !== [];
    }

    public function append(string $id, SlotHold $hold, string $binding): void
    {
        $p = $hold->proposal;
        $q = $p->quote;
        $this->e->insert('b231_claims', ['id' => $id, 'proposal_id' => $p->reservationId, 'inquiry_id' => $q->inquiryId, 'service_id' => $q->serviceId, 'provider_id' => $q->providerProfileId, 'provider_user_id' => $q->providerUserId, 'client_id' => $q->clientUserId, 'starts_at_us' => \Tests\Support\HoldsSqlite\HoldValues::micros($p->interval->start), 'ends_at_us' => \Tests\Support\HoldsSqlite\HoldValues::micros($p->interval->end), 'origin' => 'payment', 'hold_id' => $hold->id, 'binding_id' => $binding, 'phase' => 'held', 'expected_hold' => 'held', 'reason' => 'accepted', 'version' => 1, 'at_us' => $this->e->at]);
    }

    public function findForProposal(int $id): array
    {
        return $this->e->query('SELECT * FROM b231_claims WHERE proposal_id=?', [$id])[0] ?? throw new \LogicException('Missing claim');
    }

    public function transition(int $proposal, int $expected, string $phase, string $reason, ?string $holdState): void
    {
        $r = $this->e->query('UPDATE b231_claims SET phase=?,reason=?,expected_hold=?,version=version+1,at_us=?,command_id=? WHERE proposal_id=? AND version=? RETURNING id', [$phase, $reason, $holdState, $this->e->at, $this->e->command->id, $proposal, $expected]);
        if (count($r) !== 1) {
            throw new \LogicException('Claim version conflict');
        }
    }

    public function activate(string $id, string $binding, string $hold, string $claim, int $provider): void
    {
        $this->e->insert('b231_activations', ['id' => $id, 'binding_id' => $binding, 'hold_id' => $hold, 'claim_id' => $claim, 'at_us' => $this->e->at, 'provider_user_id' => $provider]);
    }

    public function financial(string $id, string $binding, string $hold, int $amount, bool $late): void
    {
        $this->e->insert('b231_financial', ['id' => $id, 'binding_id' => $binding, 'hold_id' => $hold, 'amount_cents' => $amount, 'synthetic' => 1, 'disposition' => $late ? 'late_reconcile' : 'verified_fixture', 'at_us' => $this->e->at]);
    }
}
