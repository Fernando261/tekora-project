<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldReceipt;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\HoldStore;
use App\Scheduling\Holds\HoldViolation;
use App\Scheduling\Holds\SlotHold;
use Closure;
use DateTimeImmutable;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/** No Laravel binding: a test-only persistent implementation of the exact B2.1 port. */
final class SqliteHoldStore implements HoldStore
{
    private bool $active = false;

    private bool $reading = false;

    private bool $poisoned = false;

    private readonly SqliteHoldContention $contention;

    private function __construct(private readonly SyntheticHoldDatabase $database, private readonly PDO $pdo, int $attempts, ?Closure $onBusy, private readonly ?Closure $afterCommit, private readonly ?Closure $beforeCommit)
    {
        $this->contention = new SqliteHoldContention($attempts, $onBusy, $database->connectionBusyEvents($pdo));
    }

    public static function owned(SyntheticHoldDatabase $database, PDO $pdo, int $attempts = 3, ?Closure $onBusy = null, ?Closure $afterCommit = null, ?Closure $beforeCommit = null): self
    {
        if (! $database->owns($pdo) || $attempts < 1 || $attempts > 3) {
            throw new LogicException('Owned lab connection and 1..3 attempts required.');
        }

        return new self($database, $pdo, $attempts, $onBusy, $afterCommit, $beforeCommit);
    }

    private function guard(): void
    {
        if ($this->poisoned) {
            throw new LogicException('Reconnect and resolve the same intention.');
        }
        if (! $this->database->owns($this->pdo)) {
            throw new LogicException('Foreign or invalidated connection.');
        }
        if ($this->active || $this->reading) {
            // An active transaction must take the rollback path, not replay admission.
            $this->database->check($this->pdo);

            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Unknown transaction context.');
        }
        $this->contention->run('admission', fn () => $this->database->check($this->pdo));
    }

    public function atomic(callable $operation): mixed
    {
        $this->guard();
        if ($this->active || $this->reading || $this->pdo->inTransaction()) {
            throw new LogicException('Unknown or nested transaction.');
        }
        $this->control('BEGIN IMMEDIATE', 'begin');
        $this->active = true;
        $confirmed = false;
        try {
            $result = $operation();
            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)();
            }
            $this->control('COMMIT', 'commit');
            $confirmed = true;
            if ($this->afterCommit !== null) {
                ($this->afterCommit)();
            } // Test-only lost acknowledgement seam.

            return $result; // Returned business rejection is deliberately committed by the engine contract.
        } catch (Throwable $error) {
            if ($confirmed) {
                $this->invalidate();
                throw new HoldCommitUncertain('COMMIT acknowledgement lost; reconnect and resolve the same intention.', previous: $error);
            }
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $rollback) {
                $this->invalidate();
                throw new HoldCommitUncertain('Rollback not established; reconnect and resolve the same intention.', previous: $rollback);
            }
            if ($error instanceof PDOException && SqliteHoldContention::primary($error) === 5) {
                $this->contention->record('body', 1, $error);
                throw new SqliteHoldBusy('body', 1);
            }
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    private function invalidate(): void
    {
        $this->poisoned = true;
        $this->database->invalidate($this->pdo);
    }

    private function control(string $sql, string $phase): void
    {
        $this->contention->run($phase, fn () => $this->pdo->exec($sql));
    }

    public function busyEvents(): array
    {
        return $this->contention->events();
    }

    private function snapshot(callable $read): mixed
    {
        $this->guard();
        if ($this->active || $this->reading) {
            return $read();
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Unknown read transaction.');
        }
        $this->control('BEGIN', 'read_begin');
        $this->reading = true;
        try {
            $result = $read();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $rollback) {
                $this->invalidate();
                throw new LogicException('Uncertain read context; reconnect.', previous: $rollback);
            }
            if ($error instanceof PDOException && SqliteHoldContention::primary($error) === 5) {
                $this->contention->record('read', 1, $error);
                throw new SqliteHoldBusy('read', 1);
            }
            throw $error;
        } finally {
            $this->reading = false;
        }
    }

    private function rows(string $sql, array $values = []): array
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($values) as $i => $value) {
            $statement->bindValue($i + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,$value === null => PDO::PARAM_NULL,default => PDO::PARAM_STR
            });
        }
        try {
            $statement->execute();

            return $statement->fetchAll();
        } finally {
            $statement->closeCursor();
        }
    }

    private function events(string $id): array
    {
        return array_map(HoldValues::event(...), $this->rows('SELECT * FROM main.b222_hold_events WHERE hold_id=? ORDER BY version', [$id]));
    }

    private function load(string $id): ?SlotHold
    {
        $rows = $this->rows('SELECT * FROM main.b222_holds WHERE hold_id=?', [HoldValues::uuid($id)]);

        return $rows === [] ? null : HoldValues::hydrate($rows[0], $this->events($id));
    }

    public function find(string $id): ?SlotHold
    {
        return $this->snapshot(fn () => $this->load($id));
    }

    private function list(string $where = '', array $values = []): array
    {
        return $this->snapshot(fn () => array_map(fn (array $row) => HoldValues::hydrate($row, $this->events($row['hold_id'])), $this->rows('SELECT * FROM main.b222_holds '.$where.' ORDER BY hold_id', $values)));
    }

    public function all(): array
    {
        return $this->list();
    }

    public function forProvider(int $providerProfileId): array
    {
        return $this->list('WHERE provider_profile_id=?', [HoldValues::positive($providerProfileId)]);
    }

    public function forReservation(int $reservationId): ?SlotHold
    {
        return $this->list('WHERE reservation_id=?', [HoldValues::positive($reservationId)])[0] ?? null;
    }

    public function forQuote(string $quoteId): ?SlotHold
    {
        return $this->list('WHERE quote_ref=?', [$quoteId])[0] ?? null;
    }

    public function history(string $holdId): array
    {
        return $this->snapshot(function () use ($holdId) {
            $hold = $this->load($holdId);

            return $hold === null ? [] : $this->events($holdId);
        });
    }

    public function lastClock(): ?DateTimeImmutable
    {
        return $this->snapshot(function () {
            $row = $this->rows('SELECT last_observed_at_us FROM main.b222_clock WHERE singleton=1');

            return $row === [] ? null : HoldValues::instant($row[0]['last_observed_at_us']);
        });
    }

    private function requireWriter(): void
    {
        $this->guard();
        if (! $this->active) {
            throw new LogicException('Writes require owned atomic().');
        }
    }

    public function observeClock(DateTimeImmutable $at): void
    {
        $this->requireWriter();
        $us = HoldValues::micros($at);
        $row = $this->rows('SELECT last_observed_at_us FROM main.b222_clock WHERE singleton=1');
        if ($row !== [] && $us < $row[0]['last_observed_at_us']) {
            throw new HoldViolation('clock_regressed');
        }
        if ($row === []) {
            $this->write('INSERT INTO main.b222_clock VALUES(1,?)', [$us]);
        } else {
            $this->write('UPDATE main.b222_clock SET last_observed_at_us=? WHERE singleton=1', [$us]);
        }
    }

    public function insert(SlotHold $hold, HoldEvent $event): void
    {
        $this->requireWriter();
        $row = HoldValues::row($hold, $event);
        if ($hold->state !== HoldState::Held || $hold->version !== 1) {
            throw new HoldViolation('invalid_insert');
        }
        $identity = ['provider_profile_id' => $row['provider_profile_id'], 'provider_user_id' => $row['provider_user_id']];
        $known = $this->rows('SELECT provider_profile_id,provider_user_id FROM main.b222_provider_identities WHERE provider_profile_id=? OR provider_user_id=?', array_values($identity));
        if ($known === []) {
            $this->append('b222_provider_identities', $identity);
        } elseif (count($known) !== 1 || $known[0] !== $identity) {
            throw new HoldViolation('provider_identity_conflict');
        }
        $this->append('b222_holds', $row);
    }

    public function transition(SlotHold $next, int $expectedVersion, HoldEvent $event): void
    {
        $this->requireWriter();
        $row = HoldValues::row($next, $event);
        $current = $this->load($next->id) ?? throw new HoldViolation('not_found');
        if ($current->version !== $expectedVersion) {
            throw new HoldViolation('version_conflict');
        }
        if ($current->proposal->fingerprint() !== $next->proposal->fingerprint() || $current->acceptedAt != $next->acceptedAt || $current->expiresAt != $next->expiresAt) {
            throw new HoldViolation('immutable_binding');
        }
        $valid = $current->transition($next->state, $next->updatedAt);
        if ($valid->version !== $next->version) {
            throw new HoldViolation('invalid_transition');
        }
        $fields = ['state', 'version', 'updated_at_us', 'actor_kind', 'actor_user_id'];
        $values = array_map(fn ($key) => $row[$key], $fields);
        $changed = $this->write('UPDATE main.b222_holds SET '.implode(',', array_map(fn ($key) => $key.'=?', $fields))." WHERE hold_id=? AND version=? AND state='held'", [...$values, $next->id, $expectedVersion]);
        if ($changed !== 1) {
            throw new HoldViolation('version_conflict');
        }
    }

    public function remember(HoldReceipt $receipt): void
    {
        $this->requireWriter();
        $hold = $this->load($receipt->holdId) ?? throw new LogicException('Receipt without hold.');
        $events = $this->events($hold->id);
        $this->append('b222_hold_receipts', HoldValues::receipt($receipt, $hold, $events[array_key_last($events)]));
    }

    public function receipt(string $scopeKey): ?HoldReceipt
    {
        HoldValues::scope($scopeKey);

        return $this->snapshot(function () use ($scopeKey) {
            $rows = $this->rows('SELECT * FROM main.b222_hold_receipts WHERE scope_key=?', [$scopeKey]);
            if ($rows === []) {
                return null;
            }
            $row = $rows[0];
            $hold = $this->load($row['hold_id']) ?? throw new LogicException('Orphan receipt.');
            $event = $this->events($hold->id)[HoldValues::positive($row['event_version']) - 1] ?? throw new LogicException('Missing receipt event.');
            $receipt = new HoldReceipt($row['scope_key'], $row['fingerprint'], $row['hold_id']);
            if (HoldValues::receipt($receipt, $hold, $event) !== $row) {
                throw new LogicException('Receipt reconstruction mismatch.');
            }

            return $receipt;
        });
    }

    private function append(string $table, array $row): void
    {
        $this->write('INSERT INTO main.'.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
    }

    private function write(string $sql, array $values): int
    {
        $statement = $this->pdo->prepare($sql);
        try {
            foreach ($values as $i => $value) {
                $statement->bindValue($i + 1, $value, match (true) {
                    is_int($value) => PDO::PARAM_INT,$value === null => PDO::PARAM_NULL,default => PDO::PARAM_STR
                });
            }
            $statement->execute();

            return $statement->rowCount();
        } catch (PDOException $error) {
            if (SqliteHoldContention::primary($error) === 19) {
                foreach (['slot_conflict', 'provider_identity_conflict', 'invalid_insert', 'invalid_transition', 'immutable_binding', 'invalid_clock', 'invalid_history', 'invalid_receipt', 'clock_regressed'] as $reason) {
                    if (str_contains($error->getMessage(), $reason)) {
                        throw new HoldViolation($reason);
                    }
                }
                throw new HoldViolation('storage_constraint');
            }
            throw $error;
        } finally {
            $statement->closeCursor();
        }
    }
}
