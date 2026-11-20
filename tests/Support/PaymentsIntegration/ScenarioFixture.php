<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Payments\CommercialAmount;
use App\Payments\PriceVersion;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\UtcInterval;
use Closure;
use LogicException;
use Tests\Support\HoldsSqlite\HoldValues as H;

/** Lab commands only. Actor factories are assertions, never real authentication. */
final readonly class ScenarioFixture
{
    public function __construct(public SharedSqliteUnitOfWork $unit, private ?Closure $stage = null) {}

    private function step(string $name): void
    {
        if ($this->stage !== null) {
            ($this->stage)($name);
        }
    }

    public static function intent(string $action, HoldActor $actor, string $key, array $payload): CommandEnvelope
    {
        $namespace = match ($action) {
            'publish' => 'price','quote' => 'quote','propose' => 'proposal',default => 'hold'
        };
        $scope = match ($action) {
            'publish' => 'service:'.$payload[0],'quote' => 'client:'.$actor->userId,default => $actor->scope()
        };

        return new CommandEnvelope($namespace, $scope, $key, $action, $actor, $payload);
    }

    public function publish(int $provider, int $service, int $cents, int $expected, string $key): TransactionOutcome
    {
        $actor = HoldActor::provider($provider);
        $c = self::intent('publish', $actor, $key, [$service, $cents, $expected]);

        return $this->unit->run($c, function (WriteSession $s) use ($provider, $service, $cents, $expected, $key): array {
            $this->step('work');
            $ctx = $s->pricing->service($service);
            if ($ctx['user_id'] !== $provider) {
                throw new LogicException('Provider does not own service');
            }
            $old = $s->pricing->latestPrice($service);
            if (($old?->version ?? 0) !== $expected) {
                throw new LogicException('Price version conflict');
            }
            $p = new PriceVersion(CommandEnvelope::uuid(), $service, $ctx['provider_id'], $expected + 1, CommercialAmount::fromCents($cents), $provider, $key, H::instant($s->at));
            $s->pricing->appendPrice($p);
            $this->step('price');

            return ['code' => 'published', 'price_id' => $p->id];
        });
    }

    public function quote(int $client, int $inquiry, string $key): TransactionOutcome
    {
        $c = self::intent('quote', HoldActor::client($client), $key, [$inquiry]);

        return $this->unit->run($c, function (WriteSession $s) use ($client, $inquiry, $key): array {
            $this->step('work');
            $ctx = $s->pricing->context($inquiry);
            if ($ctx['client_id'] !== $client || $ctx['status'] !== 'accepted') {
                throw new LogicException('Client does not own accepted inquiry');
            }
            $p = $s->pricing->latestPrice($ctx['service_id']) ?? throw new LogicException('No price');
            $at = H::instant($s->at);
            $q = new SyntheticQuote(CommandEnvelope::uuid(), $inquiry, $ctx['service_id'], $ctx['provider_id'], $client, $p, $key, $at, $at->modify('+1800 seconds'));
            $s->pricing->appendQuote($q);
            $this->step('quote');

            return ['code' => 'quoted', 'quote_id' => $q->id];
        });
    }

    public function propose(int $client, string $quote, int $id, int $start, string $key): TransactionOutcome
    {
        $c = self::intent('propose', HoldActor::client($client), $key, [$quote, $id, $start]);

        return $this->unit->run($c, function (WriteSession $s) use ($client, $quote, $id, $start): array {
            $this->step('work');
            $q = $s->pricing->quote($quote);
            $ctx = $s->pricing->context($q->inquiryId);
            if ($q->clientId !== $client || $s->at >= H::micros($q->expiresAt)) {
                throw new LogicException('Invalid client or expired quote');
            }
            $a = $s->pricing->acceptance($quote);
            if ($a === null) {
                $s->pricing->appendAcceptance(new QuoteAcceptance($quote, $client, H::instant($s->at)));
            }
            $this->step('consent');
            $p = new ReservationProposal($id, 1, new UtcInterval(H::instant($start), H::instant($start + 3600000000)), new AcceptedQuoteReference($quote, $q->inquiryId, $q->serviceId, $q->providerProfileId, $ctx['provider_user_id'], $client));
            $binding = $s->proposals->append($p);
            $this->step('binding');

            return ['code' => 'proposed', 'proposal_id' => $id, 'binding_id' => $binding];
        });
    }

    public function accept(int $provider, int $id, int $expected, string $key): TransactionOutcome
    {
        $c = self::intent('accept', HoldActor::provider($provider), $key, [$id, $expected]);

        return $this->unit->run($c, function (WriteSession $s) use ($provider, $id, $expected): array {
            $this->step('work');
            $r = $s->proposals->context($id);
            if ($provider !== $r['provider_user_id']) {
                throw new LogicException('Wrong provider');
            }
            self::expire($s, $r['provider_id']);
            $r = $s->proposals->context($id);
            if ($r['state_version'] !== $expected) {
                throw new LogicException('Proposal version conflict');
            }
            if ($r['state'] !== 'requested') {
                return self::reject('proposal_terminal');
            }
            $q = $s->pricing->quote($r['quote_id']);
            $a = $s->pricing->acceptance($q->id);
            if ($a === null || $a->clientId !== $r['client_id']) {
                throw new LogicException('Missing matching consent');
            }
            if ($s->at >= H::micros($q->expiresAt)) {
                $s->proposals->state($id, $expected, 'expired', null, null);

                return self::reject('requote_required');
            }
            if ($r['starts_at_us'] <= $s->at) {
                return self::reject('service_started');
            }
            if ($s->occupancy->conflicts($r['provider_id'], $r['starts_at_us'], $r['ends_at_us'])) {
                return self::reject('slot_conflict');
            }
            $h = SlotHold::start($s->proposals->dto($id), H::instant($s->at));
            $activation = CommandEnvelope::uuid();
            $claim = CommandEnvelope::uuid();
            $s->occupancy->activate($activation, $r['binding_id'], $h->id, $claim, $provider);
            $this->step('activation');
            $s->holds->append($h, $r['binding_id'], $activation, $claim);
            $this->step('hold');
            $s->occupancy->append($claim, $h, $r['binding_id']);
            $this->step('claim');
            $s->proposals->state($id, $expected, 'awaiting_payment', $claim, 'held');
            $this->step('head');

            return ['code' => 'held', 'hold_id' => $h->id, 'claim_id' => $claim, 'proposal_id' => $id, 'guard_until_us' => min(H::micros($q->expiresAt), H::micros($h->expiresAt))];
        });
    }

    public static function reject(string $code): array
    {
        return ['code' => $code, 'outcome' => 'rejected'];
    }

    public static function expire(WriteSession $s, ?int $provider = null): int
    {
        $due = $s->holds->due($provider);
        foreach ($due as $h) {
            $r = $s->proposals->context($h->proposal->reservationId);
            $claim = $s->occupancy->findForProposal($r['id']);
            $s->holds->transition($h->transition(HoldState::Expired, H::instant($s->at)), $h->version, HoldActor::system());
            $s->occupancy->transition($r['id'], $claim['version'], 'closed', 'expired', 'expired');
            $s->proposals->state($r['id'], $r['state_version'], 'expired', $claim['id'], 'closed');
        }

        return count($due);
    }

    public function decide(HoldActor $actor, string $action, string $hold, int $expected, string $key, ?int $syntheticAmount = null, ?string $evidenceId = null): TransactionOutcome
    {
        if (! in_array($action, ['commit', 'release'], true)) {
            throw new LogicException('Unknown hold decision');
        }
        $c = self::intent($action, $actor, $key, [$hold, $expected, $syntheticAmount, $evidenceId]);

        return $this->unit->run($c, function (WriteSession $s) use ($actor, $action, $hold, $expected, $syntheticAmount, $evidenceId): array {
            $this->step('work');
            $h = $s->holds->find($hold);
            $r = $s->proposals->context($h->proposal->reservationId);
            if (($action === 'commit' && $actor->kind !== 'system') || ($action === 'release' && ! $actor->mayRelease($h->proposal))) {
                throw new LogicException('Unauthorized hold decision');
            }
            if ($h->version !== $expected) {
                throw new LogicException('Hold version conflict');
            }
            $q = $s->pricing->quote($r['quote_id']);
            if ($action === 'commit') {
                if ($syntheticAmount !== $q->price->amount->totalCents || $evidenceId === null) {
                    throw new LogicException('Exact synthetic financial evidence required');
                }
                $s->occupancy->financial($evidenceId, $r['binding_id'], $hold, $syntheticAmount, $h->state !== HoldState::Held || $h->due(H::instant($s->at)));
            }
            if ($h->due(H::instant($s->at))) {
                self::expire($s, $r['provider_id']);

                return self::reject('hold_expired') + ['hold_id' => $hold];
            }
            if ($h->state !== HoldState::Held) {
                return self::reject('hold_terminal') + ['hold_id' => $hold];
            }
            $claim = $s->occupancy->findForProposal($r['id']);
            $target = $action === 'commit' ? HoldState::Committed : HoldState::Released;
            $s->holds->transition($h->transition($target, H::instant($s->at)), $expected, $actor);
            $this->step('hold_transition');
            $s->occupancy->transition($r['id'], $claim['version'], $action === 'commit' ? 'reserved' : 'closed', $action === 'commit' ? 'committed' : 'released', $target->value);
            $this->step('claim_transition');
            $s->proposals->state($r['id'], $r['state_version'], $action === 'commit' ? 'confirmed' : 'cancelled', $claim['id'], $action === 'commit' ? 'reserved' : 'closed');
            $this->step('head_transition');

            return ['code' => $target->value, 'hold_id' => $hold, 'guard_until_us' => H::micros($h->expiresAt)];
        });
    }

    public function close(HoldActor $actor, int $id, int $expectedState, int $expectedClaim, string $reason, string $key): TransactionOutcome
    {
        if (! in_array($reason, ['cancelled', 'completed'], true)) {
            throw new LogicException('Unknown closure');
        }
        $c = self::intent('close', $actor, $key, [$id, $expectedState, $expectedClaim, $reason]);

        return $this->unit->run($c, function (WriteSession $s) use ($actor, $id, $expectedState, $expectedClaim, $reason): array {
            $this->step('work');
            $r = $s->proposals->context($id);
            $claim = $s->occupancy->findForProposal($id);
            if (! in_array($actor->userId, [$r['client_id'], $r['provider_user_id']], true) || ! in_array($actor->kind, ['client', 'provider'], true) || ($actor->kind === 'client' && $actor->userId !== $r['client_id']) || ($actor->kind === 'provider' && $actor->userId !== $r['provider_user_id'])) {
                throw new LogicException('Wrong closure actor');
            }
            if ($r['state_version'] !== $expectedState || $claim['version'] !== $expectedClaim) {
                throw new LogicException('Closure version conflict');
            }
            if ($r['state'] !== 'confirmed' || ($reason === 'cancelled' && $s->at >= $r['starts_at_us']) || ($reason === 'completed' && ($actor->kind !== 'provider' || $s->at < $r['ends_at_us']))) {
                return self::reject('closure_not_allowed');
            }
            $s->occupancy->transition($id, $expectedClaim, 'closed', $reason, $claim['origin'] === 'legacy' ? null : 'committed');
            $s->proposals->state($id, $expectedState, $reason, $claim['id'], 'closed');

            return ['code' => $reason, 'proposal_id' => $id] + ($claim['hold_id'] === null ? [] : ['hold_id' => $claim['hold_id']]);
        });
    }
}
