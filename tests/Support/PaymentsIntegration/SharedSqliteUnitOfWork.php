<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use Closure;
use LogicException;
use PDO;
use PDOException;
use Tests\Support\HoldsSqlite\HoldValues as H;
use Throwable;
use WeakMap;
use WeakReference;

/** Sole PDO transaction owner; callbacks receive ports, never a connection. */
final class SharedSqliteUnitOfWork
{
    private ?PDO $pdo;

    private bool $active = false;

    private bool $closed = false;

    private int $epoch = 0;

    private ?int $observedTime = null;

    private int $pid;

    private WeakMap $leases;

    private WeakMap $sessions;

    private static ?WeakReference $current = null;

    public array $diagnostics = [];

    public function __construct(private readonly OwnedIntegrationLab $lab, private readonly Closure $clock, public readonly SqliteOutcomePolicy $policy = new SqliteOutcomePolicy, private readonly ?Closure $fault = null)
    {
        $this->pid = getmypid();
        $this->leases = new WeakMap;
        $this->sessions = new WeakMap;
        $this->pdo = $lab->connect($policy);
    }

    private function checkIdle(): void
    {
        if ($this->closed || $this->pid !== getmypid() || $this->active || self::$current?->get() !== null) {
            throw new LogicException('Invalid, nested or foreign unit of work');
        }
    }

    public function assertSession(WriteSession $s): void
    {
        if (! isset($this->sessions[$s])) {
            throw new LogicException('Foreign participant session');
        }
        $this->assertLease($this->sessions[$s]);
    }

    private function assertLease(SessionLease $l): void
    {
        if ($this->pid !== getmypid() || $this->closed || ! $this->active || ! isset($this->leases[$l]) || $l->epoch !== $this->epoch || self::$current?->get() !== $this) {
            throw new LogicException('Revoked or foreign session');
        }
    }

    private function session(CommandEnvelope $c, int $at): WriteSession
    {
        $l = new SessionLease($this->pid, $this->epoch);
        $this->leases[$l] = true;
        $weak = WeakReference::create($this);
        $executor = new SessionSqlExecutor(static function (SessionLease $lease, string $sql, array $params) use ($weak): array {
            $owner = $weak->get() ?? throw new LogicException('Session owner released');
            $owner->assertLease($lease);
            $owner->lab->verifyFile();
            $statement = $owner->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $statement->bindValue(is_int($k) ? $k + 1 : ':'.$k, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            }
            $statement->execute();
            $rows = $statement->columnCount() > 0 ? $statement->fetchAll() : [];
            $statement->closeCursor();
            $owner->diagnostics[] = ['connection' => spl_object_id($owner->pdo), 'epoch' => $lease->epoch, 'pid' => getmypid(), 'operation' => strtok($sql, ' ')];

            return $rows;
        }, $l, $c, $at);
        $s = new WriteSession($executor, $c, $at);
        $this->sessions[$s] = $l;

        return $s;
    }

    private function at(): int
    {
        $value = H::micros(($this->clock)());
        if ($this->observedTime !== null && $value < $this->observedTime) {
            throw new LogicException('Clock regressed during command');
        }
        $this->observedTime = $value;

        return $value;
    }

    private function hook(string $phase): void
    {
        if ($this->fault !== null) {
            ($this->fault)($phase);
        }
    }

    private function revoke(bool $release = true): void
    {
        $this->leases = new WeakMap;
        $this->sessions = new WeakMap;
        if ($release && self::$current?->get() === $this) {
            self::$current = null;
        }
    }

    private function rollback(Throwable $cause): never
    {
        try {
            $this->hook('rollback');
            $this->pdo->exec('ROLLBACK');
            $this->active = false;
        } catch (Throwable $rollback) {
            $this->poison();
            throw new IntegrationUncertain('Rollback not established', previous: $rollback);
        }
        $this->revoke();
        if (SqliteOutcomePolicy::code($cause) === 5) {
            $this->policy->record('body', 1, $cause);
            throw new IntegrationBusy('Body rolled back; explicit retry only', previous: $cause);
        }
        throw $cause;
    }

    private function poison(): void
    {
        $this->closed = true;
        $this->active = false;
        $this->revoke();
        $this->pdo = null;
    }

    public function close(): void
    {
        if ($this->pid !== getmypid()) {
            throw new LogicException('Inherited owner is unusable');
        }
        if ($this->active) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $e) {
                $this->poison();
                throw new IntegrationUncertain('Close could not roll back', previous: $e);
            }
        }
        $this->poison();
    }

    public function run(CommandEnvelope $command, Closure $work): TransactionOutcome
    {
        return $this->execute($command, $work, false);
    }

    public function resolve(CommandEnvelope $command): TransactionOutcome
    {
        return $this->execute($command, null, true);
    }

    private function execute(CommandEnvelope $c, ?Closure $work, bool $resolve): TransactionOutcome
    {
        $this->checkIdle();
        $this->policy->perform('admission', fn () => $this->lab->admit($this->pdo));
        $this->policy->perform('begin', fn () => $this->pdo->exec('BEGIN IMMEDIATE'));
        $this->active = true;
        self::$current = WeakReference::create($this);
        $this->epoch++;
        $this->observedTime = null;
        $committed = false;
        $phase = 'body';
        $deadline = null;
        try {
            $at = $this->at();
            $old = $this->pdo->query('SELECT at_us FROM b231_clock WHERE id=1')->fetchColumn();
            if ($old !== false && $at < $old) {
                throw new LogicException('Server clock regression');
            }
            $s = $this->session($c, $at);
            $receipt = $s->commands->receipt($c);
            if (($resolve || $receipt !== null) && $this->pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new LogicException('Inconsistent effects; resolution stopped');
            }
            if ($receipt !== null) {
                $result = json_decode($receipt['result_json'], true, flags: JSON_THROW_ON_ERROR);
                // A typed resolver command expires due holds without replaying user work.
                $due = $s->holds->due();
                $expiryIdentity = CommandEnvelope::json(array_map(fn ($h) => [$h->id, $h->version, H::micros($h->expiresAt)], $due));
                $expire = new CommandEnvelope('resolve', 'system:system', CommandEnvelope::stableKey('expire:'.$expiryIdentity), 'resolve', \App\Scheduling\Holds\HoldActor::system(), ['cause' => $receipt['id']]);
                $resolver = $this->session($expire, $at);
                $expired = ScenarioFixture::expire($resolver);
                if ($expired > 0) {
                    $this->watermark($expire, $at);
                    $resolver->commands->seal(['code' => 'expired', 'count' => $expired]);
                }
                $kind = 'replayed';
            } elseif ($resolve) {
                $check = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll();
                if ($check !== []) {
                    throw new LogicException('Partial effects during resolution');
                }
                $result = ['code' => 'not_committed'];
                $kind = 'not_committed';
            } else {
                $this->watermark($c, $at);
                $result = $work($s);
                if (! is_array($result) || ! isset($result['code'])) {
                    throw new LogicException('Explicit decision required');
                }
                $this->assertSession($s);
                $this->hook('before_seal');
                $deadline = $result['guard_until_us'] ?? null;
                $this->at();
                if ($deadline !== null && $this->at() >= $deadline) {
                    throw new LogicException('Admission deadline crossed before seal');
                }
                $s->commands->seal($result);
                $this->hook('after_seal');
                $kind = ($result['outcome'] ?? 'success') === 'rejected' ? 'rejected' : 'committed';
            }
            $this->revoke(false); // no participant can write while COMMIT is pending
            $phase = 'commit';
            $this->policy->perform('commit', function () use ($deadline): void {
                if ($deadline !== null && $this->at() >= $deadline) {
                    throw new LogicException('Known deadline abort before COMMIT');
                }
                $this->hook('before_commit');
                $this->pdo->exec('COMMIT');
            });
            $committed = true;
            $this->active = false;
            $this->hook('after_commit');
            $current = $this->current($result);
            $pending = $current !== null && ($current['state'] ?? null) === 'held' && $this->at() >= $current['expires_at_us'];

            return new TransactionOutcome($kind, $result, $current, $pending);
        } catch (Throwable $e) {
            if ($committed) {
                $this->poison();
                throw new IntegrationUncertain('COMMIT confirmed; acknowledgement unavailable', previous: $e);
            }
            // Deferred FK failures are a known rejected COMMIT, ordinary BUSY is known pending.
            if ($phase === 'commit' && $e instanceof PDOException && ! in_array(SqliteOutcomePolicy::code($e), [5, 19, 787], true)) {
                $this->poison();
                throw new IntegrationUncertain('Unrecognized COMMIT result', previous: $e);
            }
            if (! $this->active) {
                throw $e;
            }
            $this->rollback($e);
        } finally {
            $this->revoke();
        }
    }

    private function watermark(CommandEnvelope $c, int $at): void
    {
        if ($this->pdo->query('SELECT count(*) FROM b231_clock')->fetchColumn() === 0) {
            $q = $this->pdo->prepare('INSERT INTO b231_clock VALUES(1,?,?)');
        } else {
            $q = $this->pdo->prepare('UPDATE b231_clock SET at_us=?,command_id=? WHERE id=1');
        }
        $q->execute([$at, $c->id]);
        $q->closeCursor();
    }

    private function current(array $result): ?array
    {
        if (! isset($result['hold_id'])) {
            return null;
        }
        $q = $this->pdo->prepare('SELECT h.hold_id,h.state,h.version,h.accepted_at_us,h.expires_at_us,c.phase,c.version AS claim_version,r.state AS proposal_state FROM b231_holds h JOIN b231_claims c ON c.id=h.claim_id JOIN b231_heads r ON r.id=h.reservation_id WHERE h.hold_id=?');
        $q->execute([$result['hold_id']]);
        $r = $q->fetch();
        $q->closeCursor();

        return $r ?: null;
    }
}
