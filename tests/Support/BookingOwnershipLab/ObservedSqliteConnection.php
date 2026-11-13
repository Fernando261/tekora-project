<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use PDO;
use Throwable;
use WeakReference;

final class ObservedSqliteConnection extends SQLiteConnection
{
    private bool $booting = true;

    private ?string $access = null;

    private WeakReference $original;

    private int $ownerPid;

    private readonly string $connectionEpoch;

    private int $generation = 0;

    private ?TransactionWitness $witness = null;

    private ?WeakReference $lease = null;

    private NativeOutcome $outcome = NativeOutcome::NOT_STARTED;

    private string $phase = 'UNOWNED_IDLE';

    private bool $nativeInvoked = false;

    private bool $commitUncertain = false;

    private bool $identityValid = true;

    private bool $retired = false;

    private bool $sealed = false;

    private ?LabFailure $firstError = null;

    private array $trace = [];

    public function __construct(PDO $pdo, private readonly LabFixture $fixture, private readonly FaultPlan $faults, private readonly object $authority)
    {
        $fixture->assertProfile($pdo);
        $this->original = WeakReference::create($pdo);
        $this->ownerPid = getmypid();
        $this->connectionEpoch = bin2hex(random_bytes(16));
        parent::__construct($pdo, $fixture->path(), '', ['name' => 'private_ownership_lab', 'driver' => 'sqlite', 'transaction_mode' => 'DEFERRED']);
        $this->booting = false;
        $this->recordPhase('CONNECTION_ACQUIRED');
    }

    public function beginOwned(object $authority): void
    {
        $this->checkAuthority($authority);
        $this->identity();
        if ($this->transactions !== 0 || $this->pdo->inTransaction()) {
            $this->phase = 'REJECTED_FOREIGN';
            throw new LabFailure('ForeignTransaction', $this->phase);
        }
        $this->fixture->assertProfile($this->pdo);
        $this->phase = 'START_REQUESTED';
        $this->nativeInvoked = false;
        $this->recordPhase($this->phase);
        try {
            $this->faults->hit(FaultPoint::BEFORE_NATIVE_BEGIN);
            $this->access = 'BEGIN';
            $this->beginTransaction();
            $this->faults->hit(FaultPoint::AFTER_LEVEL_BEFORE_WRITE);
            if ($this->transactions !== 1 || ! $this->owned()) {
                throw new LabFailure('WitnessMissing', $this->phase);
            }
        } catch (Throwable $error) {
            if (! $this->witness && $this->nativeInvoked && LabFailure::isForeignBegin($error)) {
                $this->phase = 'REJECTED_FOREIGN';
                $failure = new LabFailure('ForeignTransaction', $this->phase, $error);
            } else {
                $this->phase = ! $this->nativeInvoked ? 'START_NOT_STARTED' : ($this->witness ? 'NATIVE_STARTED' : 'START_UNKNOWN');
                if ($this->phase === 'START_UNKNOWN') {
                    $this->outcome = NativeOutcome::UNKNOWN;
                }
                $failure = LabFailure::from($error, $this->phase, 'BeginFailure');
            }
            $this->firstError ??= $failure;
            $this->recordPhase($this->phase, ['error' => $failure->jsonSerialize()]);
            throw $failure;
        } finally {
            $this->access = null;
        }
    }

    public function beginTransaction()
    {
        if ($this->access !== 'BEGIN') {
            $this->rejectMutation();
        }
        parent::beginTransaction();
    }

    protected function executeBeginTransactionStatement()
    {
        $this->identity();
        $this->nativeInvoked = true;
        parent::executeBeginTransactionStatement();
        $this->faults->hit(FaultPoint::AFTER_NATIVE_BEFORE_WITNESS);
        $this->witness = TransactionWitness::nativeReturned($this->pdo, $this->connectionEpoch, ++$this->generation);
        $this->outcome = NativeOutcome::ACTIVE;
        $this->phase = 'NATIVE_STARTED';
        $this->recordPhase($this->phase);
        $this->faults->hit(FaultPoint::AFTER_WITNESS_BEFORE_LEVEL);
    }

    protected function handleBeginTransactionException(Throwable $e)
    {
        throw $e; // Never delegate to Laravel's reconnect/retry path.
    }

    public function acquireWriter(object $authority, InvocationSpec $invocation): void
    {
        $this->checkAuthority($authority);
        try {
            $this->faults->hit(FaultPoint::BEFORE_COORDINATION);
            $this->faults->mutateCoordination($this->pdo, false);
            $this->access = 'ACQUIRE';
            $row = parent::selectOne('SELECT epoch,admission_version FROM coordination WHERE id=1');
            if (! $row || $row->epoch !== $invocation->epoch || $invocation->epoch !== $this->fixture->epoch()) {
                throw new LabFailure('CoordinationMismatch', 'ACQUIRE');
            }
            $this->faults->mutateCoordination($this->pdo, true);
            $count = parent::update('UPDATE coordination SET admission_version=admission_version+1,owner_nonce=? WHERE id=1 AND epoch=? AND admission_version=?', [$this->witness->nonce(), $invocation->epoch, $row->admission_version]);
            if ($count !== 1) {
                throw new LabFailure('CoordinationMismatch', 'ACQUIRE');
            }
            $this->phase = 'OWN_ACTIVE';
            $this->recordPhase($this->phase);
        } catch (Throwable $error) {
            throw $this->poison($error);
        } finally {
            $this->access = null;
        }
    }

    public function attachLease(object $authority, SessionLease $lease): void
    {
        $this->checkAuthority($authority);
        if (! $this->owned() || $this->phase !== 'OWN_ACTIVE') {
            throw new LabFailure('WitnessMissing', $this->phase);
        }
        $this->lease = WeakReference::create($lease);
    }

    public function operate(object $authority, InvocationSpec $invocation, ProbeOperation $operation, int $value = 0): void
    {
        $this->checkAuthority($authority);
        try {
            // Privileged harness corrupts the expected identity, never transfers the real PDO.
            $mutation = $this->faults->identityMutation();
            if ($mutation === 'identity') {
                $this->original = WeakReference::create($this->authority);
            } elseif ($mutation === 'pid') {
                $this->ownerPid = -1;
            } elseif ($mutation === 'generation') {
                $this->generation++;
            }
            if ($mutation !== null) {
                $this->recordPhase('INJECTED_IDENTITY_EXPECTATION', ['kind' => $mutation]);
            }
            $this->faults->hit(FaultPoint::BEFORE_DATA);
            $this->identity();
            if ($this->phase !== 'OWN_ACTIVE' || $this->firstError !== null || $this->sealed || ! $this->lease?->get()?->permitsWrite()) {
                throw new LabFailure($this->sealed ? 'OperationAfterSeal' : 'SessionPoisoned', $this->phase);
            }
            $this->access = 'DATA';
            $bindings = [$invocation->probeKey, $invocation->id];
            match ($operation) {
                ProbeOperation::A => parent::insert('INSERT INTO probe_a (probe_key,invocation_id,a_value) VALUES (?,?,11)', $bindings),
                ProbeOperation::B => $this->writeB($bindings, $value),
                ProbeOperation::OUTBOX => parent::insert("INSERT INTO probe_outbox VALUES (?, ?, ?, 'probe', 0)", [...$bindings, 'event-'.$invocation->id]),
                ProbeOperation::RECEIPT => parent::insert('INSERT INTO probe_receipt VALUES (?,?,11,22,?,1)', [...$bindings, 'event-'.$invocation->id]),
                ProbeOperation::BROKEN_STATEMENT => $this->brokenStatement(),
            };
            $this->recordPhase('WRITE_'.$operation->value);
            if ($operation === ProbeOperation::RECEIPT) {
                $this->sealed = true;
            }
            $point = match ($operation) {
                ProbeOperation::A => FaultPoint::AFTER_A,
                ProbeOperation::B => FaultPoint::AFTER_B,
                ProbeOperation::OUTBOX => FaultPoint::AFTER_OUTBOX,
                default => FaultPoint::AFTER_RECEIPT,
            };
            $this->faults->hit($point);
        } catch (Throwable $error) {
            if ($error instanceof LabFailure && in_array($error->reason, ['IdentityMismatch', 'ProcessMismatch'], true)) {
                $this->identityValid = false;
            }
            throw $this->poison($error);
        } finally {
            $this->access = null;
        }
    }

    private function writeB(array $bindings, int $value): bool
    {
        if ($value !== 22 && ! ($value === -777 && $this->fixture->variant() === 'engine_abort')) {
            throw new LabFailure('UnsupportedProbeValue', 'DATA');
        }

        return parent::insert('INSERT INTO probe_b (probe_key,invocation_id,b_value) VALUES (?,?,?)', [...$bindings, $value]);
    }

    private function brokenStatement(): array
    {
        if (! $this->faults->allowBrokenStatement) {
            throw new LabFailure('UnsupportedFaultPoint', 'DATA');
        }

        return parent::select('SELECT * FROM missing_lab_table');
    }

    public function verifyGraph(object $authority, InvocationSpec $invocation): void
    {
        $this->checkAuthority($authority);
        if ($this->firstError !== null) {
            throw $this->firstError;
        }
        try {
            $this->access = 'VERIFY';
            $tables = [];
            foreach (['probe_a', 'probe_b', 'probe_outbox', 'probe_receipt'] as $table) {
                $tables[$table] = array_map(fn ($row) => (array) $row, parent::select('SELECT * FROM '.$table.' WHERE probe_key=? AND invocation_id=?', [$invocation->probeKey, $invocation->id]));
            }
            if (! $this->sealed || count($tables['probe_receipt']) !== 1 || ! LabFixture::validGraph($tables)) {
                throw new LabFailure('ReceiptMismatch', 'VERIFY');
            }
            $this->recordPhase('GRAPH_VERIFIED');
        } catch (Throwable $error) {
            throw $this->poison($error);
        } finally {
            $this->access = null;
        }
    }

    public function commitOwned(object $authority): void
    {
        $this->checkAuthority($authority);
        $this->identity();
        if (! $this->owned() || $this->firstError !== null || ! $this->sealed) {
            throw new LabFailure('WitnessMissing', 'COMMIT');
        }
        $this->phase = 'COMMIT_REQUESTED';
        $this->commitUncertain = true;
        $this->outcome = NativeOutcome::UNKNOWN;
        $this->recordPhase($this->phase);
        try {
            if (! $this->faults->hit(FaultPoint::BEFORE_NATIVE_COMMIT) || $this->pdo->commit() !== true) {
                throw new LabFailure('CommitUncertain', $this->phase);
            }
            $this->faults->hit(FaultPoint::AFTER_NATIVE_COMMIT_BEFORE_MARKER);
            $this->outcome = NativeOutcome::COMMITTED;
            $this->commitUncertain = false;
            $this->witness->revoke();
            $this->phase = 'COMMIT_CONFIRMED';
            $this->recordPhase($this->phase);
            $this->faults->hit(FaultPoint::AFTER_COMMIT_MARKER);
            $this->transactions = 0;
        } catch (Throwable $error) {
            if ($this->outcome !== NativeOutcome::COMMITTED && LabFailure::sqliteCode($error) === 5 && $this->identityMatches()
                && $this->witness?->matches($this->pdo, $this->connectionEpoch, $this->generation) && $this->pdo->inTransaction()) {
                $this->commitUncertain = false;
                $this->outcome = NativeOutcome::ACTIVE;
                $this->phase = 'COMMIT_BUSY_ACTIVE';
            }
            $this->recordPhase($this->phase, ['error' => LabFailure::from($error, $this->phase)->jsonSerialize()]);
            throw $error;
        }
    }

    public function rollbackOwned(object $authority): void
    {
        $this->checkAuthority($authority);
        if (! $this->canRollback()) {
            throw new LabFailure('WitnessMissing', 'ROLLBACK');
        }
        $this->phase = 'ROLLBACK_REQUESTED';
        $this->outcome = NativeOutcome::UNKNOWN;
        $this->recordPhase($this->phase);
        if (! $this->faults->hit(FaultPoint::BEFORE_NATIVE_ROLLBACK) || $this->pdo->rollBack() !== true) {
            throw new LabFailure('RollbackUncertain', $this->phase);
        }
        $this->faults->hit(FaultPoint::AFTER_NATIVE_ROLLBACK_BEFORE_MARKER);
        $this->outcome = NativeOutcome::ROLLED_BACK;
        $this->witness->revoke();
        $this->phase = 'ROLLBACK_CONFIRMED';
        $this->recordPhase($this->phase);
        $this->faults->hit(FaultPoint::AFTER_ROLLBACK_MARKER);
        $this->transactions = 0;
    }

    public function recoverOwnedBoundary(object $authority): void
    {
        $this->rollbackOwned($authority);
    }

    public function resetAdmission(object $authority): void
    {
        $this->checkAuthority($authority);
        if (! in_array($this->outcome, [NativeOutcome::NOT_STARTED, NativeOutcome::ROLLED_BACK], true) || $this->retired || $this->transactions !== 0) {
            throw new LabFailure('ConnectionInvalidated', 'RETRY');
        }
        $this->witness = null;
        $this->firstError = null;
        $this->outcome = NativeOutcome::NOT_STARTED;
        $this->phase = 'UNOWNED_IDLE';
        $this->recordPhase('ADMISSION_RETRY');
    }

    public function canRollback(): bool
    {
        return $this->owned() && ! $this->commitUncertain;
    }

    private function owned(): bool
    {
        return $this->identityMatches() && $this->outcome === NativeOutcome::ACTIVE
            && $this->witness?->matches($this->pdo, $this->connectionEpoch, $this->generation) === true;
    }

    private function identityMatches(): bool
    {
        return ! $this->retired && $this->identityValid && $this->ownerPid === getmypid()
            && $this->pdo instanceof PDO && $this->original->get() === $this->pdo;
    }

    private function identity(): void
    {
        if ($this->ownerPid !== getmypid()) {
            throw new LabFailure('ProcessMismatch', $this->phase);
        }
        if (! $this->identityMatches()) {
            throw new LabFailure('IdentityMismatch', $this->phase);
        }
        if ($this->outcome === NativeOutcome::ACTIVE && $this->witness !== null
            && ! $this->witness->matches($this->pdo, $this->connectionEpoch, $this->generation)) {
            throw new LabFailure('IdentityMismatch', $this->phase);
        }
        $this->fixture->verifyPaths();
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LabFailure('ProfileRejected', $this->phase);
        }
    }

    private function checkAuthority(object $authority): void
    {
        if ($authority !== $this->authority) {
            throw new LabFailure('WitnessMissing', 'AUTHORITY');
        }
    }

    private function poison(Throwable $error): LabFailure
    {
        $this->firstError ??= LabFailure::from($error, $this->phase);
        $this->lease?->get()?->poison();
        $this->recordPhase('POISONED', ['error' => $this->firstError->jsonSerialize()]);

        return $this->firstError;
    }

    protected function run($query, $bindings, Closure $callback)
    {
        try {
            $this->identity();
            if (! in_array($this->access, ['ACQUIRE', 'DATA', 'VERIFY'], true) || ! $this->owned() || $this->transactions !== 1 || $this->firstError !== null) {
                throw new LabFailure('SessionRevoked', $this->phase);
            }

            return parent::run($query, $bindings, $callback);
        } catch (Throwable $error) {
            throw $this->poison($error);
        }
    }

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        throw $e;
    }

    public function reconnectIfMissingConnection()
    {
        if (! $this->identityMatches()) {
            throw new LabFailure('ConnectionInvalidated', $this->phase);
        }
    }

    public function getPdo()
    {
        if ($this->access === null) {
            $error = new LabFailure('RawHandleRejected', $this->phase);
            throw $this->outcome === NativeOutcome::ACTIVE ? $this->poison($error) : $error;
        }
        $this->identity();

        return $this->pdo;
    }

    public function getReadPdo()
    {
        return $this->getPdo();
    }

    public function getRawPdo()
    {
        return $this->getPdo();
    }

    public function getRawReadPdo()
    {
        return $this->getPdo();
    }

    private function rejectMutation(): never
    {
        $error = new LabFailure('ProfileRejected', $this->phase);
        if ($this->outcome === NativeOutcome::ACTIVE) {
            $this->poison($error);
        }
        throw $error;
    }

    private function bootstrapOnly(): void
    {
        if (! $this->booting) {
            $this->rejectMutation();
        }
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->rejectMutation();
    }

    public function commit()
    {
        $this->rejectMutation();
    }

    public function rollBack($toLevel = null)
    {
        $this->rejectMutation();
    }

    public function afterCommit($callback)
    {
        $this->rejectMutation();
    }

    public function afterRollBack($callback)
    {
        $this->rejectMutation();
    }

    public function beforeStartingTransaction(Closure $callback)
    {
        $this->rejectMutation();
    }

    public function beforeExecuting(Closure $callback)
    {
        $this->rejectMutation();
    }

    public function listen(Closure $callback)
    {
        $this->rejectMutation();
    }

    public function whenQueryingForLongerThan($threshold, $handler)
    {
        $this->rejectMutation();
    }

    public function pretend(Closure $callback)
    {
        $this->rejectMutation();
    }

    public function setPdo($pdo)
    {
        $this->rejectMutation();
    }

    public function setReadPdo($pdo)
    {
        $this->rejectMutation();
    }

    public function setReadPdoConfig(array $config)
    {
        $this->rejectMutation();
    }

    public function setReconnector(callable $reconnector)
    {
        $this->rejectMutation();
    }

    public function reconnect()
    {
        $this->rejectMutation();
    }

    public function disconnect()
    {
        $this->rejectMutation();
    }

    public function setEventDispatcher(Dispatcher $events)
    {
        $this->rejectMutation();
    }

    public function unsetEventDispatcher()
    {
        $this->rejectMutation();
    }

    public function setTransactionManager($manager)
    {
        $this->rejectMutation();
    }

    public function unsetTransactionManager()
    {
        $this->rejectMutation();
    }

    public function setReadWriteType($type)
    {
        $this->rejectMutation();
    }

    public function setDatabaseName($database)
    {
        $this->rejectMutation();
    }

    public function setQueryGrammar(\Illuminate\Database\Query\Grammars\Grammar $grammar)
    {
        $this->bootstrapOnly();

        return parent::setQueryGrammar($grammar);
    }

    public function setSchemaGrammar(\Illuminate\Database\Schema\Grammars\Grammar $grammar)
    {
        $this->bootstrapOnly();

        return parent::setSchemaGrammar($grammar);
    }

    public function setPostProcessor(\Illuminate\Database\Query\Processors\Processor $processor)
    {
        $this->bootstrapOnly();

        return parent::setPostProcessor($processor);
    }

    public function setTablePrefix($prefix)
    {
        $this->bootstrapOnly();

        return parent::setTablePrefix($prefix);
    }

    public function harnessForeignLevel(object $authority): void
    {
        $this->checkAuthority($authority);
        $this->transactions = 1;
    }

    public function harnessProfileProbe(object $authority, string $probe): void
    {
        $this->checkAuthority($authority);
        if ($this->nativeInvoked || $this->outcome !== NativeOutcome::NOT_STARTED) {
            throw new LabFailure('UnsupportedFaultPoint', 'PREFLIGHT');
        }
        if (in_array($probe, ['silent', 'warning'], true)) {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, $probe === 'silent' ? PDO::ERRMODE_SILENT : PDO::ERRMODE_WARNING);
            $this->fixture->assertProfile($this->pdo);
        }
        match ($probe) {
            'raw' => $this->getPdo(),
            'read_pdo' => $this->setReadPdo(null),
            'manager' => $this->setTransactionManager(new \stdClass),
            'event' => $this->beforeStartingTransaction(static function () {}),
            'reconnector' => $this->setReconnector(static function () {}),
            'nested' => $this->beginTransaction(),
            'after_commit' => $this->afterCommit(static function () {}),
            'before_execute' => $this->beforeExecuting(static function () {}),
            default => throw new LabFailure('ProfileRejected', 'PREFLIGHT'),
        };
    }

    public function invalidate(object $authority): void
    {
        $this->checkAuthority($authority);
        $this->lease?->get()?->revoke();
        $this->retired = true;
        $this->recordPhase('INVALIDATED_UNRESOLVED');
    }

    public function releaseKnown(object $authority): void
    {
        $this->checkAuthority($authority);
        if (! in_array($this->outcome, [NativeOutcome::NOT_STARTED, NativeOutcome::COMMITTED, NativeOutcome::ROLLED_BACK], true)) {
            throw new LabFailure('NativeCloseUncertain', $this->phase);
        }
        $this->releaseResources();
    }

    public function releaseAfterCaller(object $authority): void
    {
        $this->checkAuthority($authority);
        if (! $this->faults->callerCloseProven()) {
            throw new LabFailure('CallerCloseNotProven', 'CALLER');
        }
        $this->releaseResources();
    }

    private function releaseResources(): void
    {
        $this->lease?->get()?->revoke();
        $this->witness?->revoke();
        $this->witness = null;
        $this->pdo = null;
        $this->readPdo = null;
        $this->retired = true;
        $this->recordPhase('INVALIDATED_CLOSED');
    }

    public function outcome(): NativeOutcome
    {
        return $this->outcome;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function firstError(): ?LabFailure
    {
        return $this->firstError;
    }

    public function trace(): array
    {
        return $this->trace;
    }

    public function nativeWasInvoked(): bool
    {
        return $this->nativeInvoked;
    }

    public function recordPhase(string $event, array $extra = []): void
    {
        $this->trace[] = ['seq' => count($this->trace), 'event' => $event, 'phase' => $this->phase, 'time_ns' => hrtime(true),
            'pid' => getmypid(), 'connection_epoch' => $this->connectionEpoch, 'generation' => $this->generation,
            'pdo_id' => $this->pdo instanceof PDO ? spl_object_id($this->pdo) : null, 'laravel_level' => $this->transactions] + $extra;
    }

    public function __clone(): void
    {
        throw new LabFailure('ConnectionInvalidated', 'CLONE');
    }

    public function __serialize(): array
    {
        throw new LabFailure('ConnectionInvalidated', 'SERIALIZE');
    }
}
