<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\UtcInterval;
use Tests\Support\HoldsSqlite\HoldValues as H;

final readonly class SharedProposalBindingRepository
{
    public function __construct(private SessionSqlExecutor $e) {}

    public function append(ReservationProposal $p): string
    {
        $q = $p->quote;
        $this->e->insert('b231_proposals', ['id' => $p->reservationId, 'snapshot_version' => $p->proposalVersion, 'inquiry_id' => $q->inquiryId, 'service_id' => $q->serviceId, 'provider_id' => $q->providerProfileId, 'provider_user_id' => $q->providerUserId, 'client_id' => $q->clientUserId, 'starts_at_us' => H::micros($p->interval->start), 'ends_at_us' => H::micros($p->interval->end)]);
        $id = CommandEnvelope::uuid();
        $this->e->insert('b231_bindings', ['id' => $id, 'proposal_id' => $p->reservationId, 'quote_id' => $q->id, 'client_id' => $q->clientUserId]);
        $this->e->insert('b231_heads', ['id' => $p->reservationId, 'state' => 'requested', 'version' => 1, 'claim_id' => null, 'claim_phase' => null, 'at_us' => $this->e->at]);

        return $id;
    }

    public function context(int $id): array
    {
        return $this->e->query('SELECT p.*,b.id AS binding_id,b.quote_id,h.state,h.version AS state_version,h.claim_id,h.claim_phase FROM b231_proposals p LEFT JOIN b231_bindings b ON b.proposal_id=p.id JOIN b231_heads h ON h.id=p.id WHERE p.id=?', [$id])[0] ?? throw new \LogicException('Unknown proposal');
    }

    public function dto(int $id): ReservationProposal
    {
        $r = $this->context($id);

        return new ReservationProposal($r['id'], $r['snapshot_version'], new UtcInterval(H::instant($r['starts_at_us']), H::instant($r['ends_at_us'])), new AcceptedQuoteReference($r['quote_id'], $r['inquiry_id'], $r['service_id'], $r['provider_id'], $r['provider_user_id'], $r['client_id']));
    }

    public function state(int $id, int $expected, string $state, ?string $claim, ?string $phase): void
    {
        $r = $this->e->query('UPDATE b231_heads SET state=?,version=version+1,claim_id=?,claim_phase=?,at_us=?,command_id=? WHERE id=? AND version=? RETURNING id', [$state, $claim, $phase, $this->e->at, $this->e->command->id, $id, $expected]);
        if (count($r) !== 1) {
            throw new \LogicException('Proposal version conflict');
        }
    }
}
