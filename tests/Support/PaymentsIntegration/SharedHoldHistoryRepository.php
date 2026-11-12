<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\SlotHold;
use Tests\Support\HoldsSqlite\HoldValues as H;

/** History remains complete even when a committed occupancy is closed. */
final readonly class SharedHoldHistoryRepository
{
    private const FIELDS = 'hold_id,reservation_id,proposal_version,quote_ref,inquiry_id,service_id,provider_profile_id,provider_user_id,client_user_id,starts_at_us,ends_at_us,state,version,accepted_at_us,expires_at_us,updated_at_us,actor_kind,actor_user_id';

    public function __construct(private SessionSqlExecutor $e) {}

    public function find(string $id): SlotHold
    {
        $r = $this->e->query('SELECT '.self::FIELDS.' FROM b231_holds WHERE hold_id=?', [H::uuid($id)])[0] ?? throw new \LogicException('Unknown hold');
        $events = [];
        foreach ($this->e->query('SELECT * FROM b231_hold_events WHERE aggregate_id=? ORDER BY version', [$id]) as $v) {
            // Actor comes from the immutable command which generated this event.
            $c = $this->e->query('SELECT actor_kind,actor_user_id FROM b231_commands WHERE id=?', [$v['command_id']]);
            $a = $c ? H::actor($c[0]) : $this->e->command->actor;
            // Expiration is internally authorized as system even during another actor's command.
            if ($v['to_state'] === 'expired') {
                $a = HoldActor::system();
            }
            $events[] = new HoldEvent($id, $v['version'], $v['from_state'] === null ? null : HoldState::from($v['from_state']), HoldState::from($v['to_state']), $a, H::instant($v['at_us']));
        }

        return H::hydrate($r, $events);
    }

    public function append(SlotHold $h, string $binding, string $activation, string $claim): void
    {
        $event = new HoldEvent($h->id, 1, null, HoldState::Held, $this->e->command->actor, H::instant($this->e->at));
        $this->e->insert('b231_holds', H::row($h, $event) + ['binding_id' => $binding, 'activation_id' => $activation, 'claim_id' => $claim]);
    }

    public function transition(SlotHold $next, int $expected, HoldActor $actor): void
    {
        $old = $this->find($next->id);
        if ($old->version !== $expected) {
            throw new \LogicException('Hold version conflict');
        }
        H::row($next, new HoldEvent($next->id, $next->version, $old->state, $next->state, $actor, $next->updatedAt));
        $r = $this->e->query('UPDATE b231_holds SET state=?,version=?,updated_at_us=?,actor_kind=?,actor_user_id=?,command_id=? WHERE hold_id=? AND version=? RETURNING hold_id', [$next->state->value, $next->version, H::micros($next->updatedAt), $actor->kind, $actor->userId, $this->e->command->id, $next->id, $expected]);
        if (count($r) !== 1) {
            throw new \LogicException('Hold CAS failed');
        }
    }

    public function due(?int $provider = null): array
    {
        $rows = $this->e->query("SELECT hold_id FROM b231_holds WHERE state='held' AND expires_at_us<=?".($provider === null ? '' : ' AND provider_profile_id=?'), $provider === null ? [$this->e->at] : [$this->e->at, $provider]);

        return array_map(fn ($r) => $this->find($r['hold_id']), $rows);
    }
}
