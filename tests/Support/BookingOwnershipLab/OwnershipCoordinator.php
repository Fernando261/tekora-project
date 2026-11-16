<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use Closure;
use Throwable;

final class OwnershipCoordinator
{
    private bool $used = false;

    public function __construct(private readonly PrivateConnectionFactory $factory, private readonly object $authority) {}

    public function run(InvocationSpec $invocation, Closure $callback): RunResult
    {
        PrivateConnectionFactory::assertAdmission();
        if ($this->used) {
            throw new LabFailure('ConnectionInvalidated', 'INVOCATION');
        }
        $this->used = true;
        $connection = null;
        $lease = null;
        $primary = null;
        $secondary = [];
        $count = 0;
        $foreign = false;
        try {
            $connection = $this->factory->open($this->authority);
            $deadline = hrtime(true) + 250_000_000;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $connection->recordPhase('BEGIN_ATTEMPT', ['attempt' => $attempt]);
                try {
                    $connection->beginOwned($this->authority);
                    $connection->acquireWriter($this->authority, $invocation);
                    break;
                } catch (Throwable $error) {
                    $failure = LabFailure::from($error, $connection->phase());
                    $foreign = $connection->phase() === 'REJECTED_FOREIGN';
                    $cleanupFailed = false;
                    if (! $foreign && $connection->canRollback()) {
                        try {
                            $connection->recoverOwnedBoundary($this->authority);
                        } catch (Throwable $closeError) {
                            $secondary[] = LabFailure::from($closeError, 'ROLLBACK', 'RollbackUncertain');
                            $cleanupFailed = true;
                        }
                    }
                    $safe = ! $foreign && ! $cleanupFailed && in_array($connection->outcome(), [NativeOutcome::NOT_STARTED, NativeOutcome::ROLLED_BACK], true);
                    if ($safe && LabFailure::sqliteCode($failure) === 5) {
                        $this->factory->faults->admissionBusyClosed();
                    }
                    if ($safe && LabFailure::sqliteCode($failure) === 5 && $attempt < 3 && $this->waitForRetry($connection, $deadline, $attempt)) {
                        $connection->resetAdmission($this->authority);

                        continue;
                    }
                    throw $failure;
                }
            }
            $lease = new SessionLease(fn (ProbeOperation $operation, int $value) => $connection->operate($this->authority, $invocation, $operation, $value));
            $connection->attachLease($this->authority, $lease);
            $count++;
            $connection->recordPhase('CALLBACK', ['callback_count' => $count]);
            $callback($lease);
            $lease->revoke();
            $connection->verifyGraph($this->authority, $invocation);
            $deadline = hrtime(true) + 250_000_000;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $connection->recordPhase('COMMIT_ATTEMPT', ['attempt' => $attempt]);
                try {
                    $connection->commitOwned($this->authority);
                    break;
                } catch (Throwable $error) {
                    if ($connection->outcome() === NativeOutcome::COMMITTED) {
                        $secondary[] = LabFailure::from($error, 'COMMIT_CONFIRMED', 'BookkeepingFailure');
                        break;
                    }
                    if ($connection->phase() === 'COMMIT_BUSY_ACTIVE' && $attempt < 3 && $this->waitForRetry($connection, $deadline, $attempt)) {
                        continue; // Same native transaction, never the callback.
                    }
                    throw $error;
                }
            }
        } catch (Throwable $error) {
            $errorPhase = $connection?->phase() ?? 'PREFLIGHT';
            $errorReason = match ($errorPhase) {
                'COMMIT_REQUESTED' => 'CommitUncertain',
                'COMMIT_BUSY_ACTIVE' => 'CommitBusyExhausted',
                'PREFLIGHT', 'UNOWNED_IDLE' => 'ProfileRejected',
                default => 'CallbackFailure',
            };
            $primary = $connection?->firstError() ?? LabFailure::from($error, $errorPhase, $errorReason);
            $lease?->revoke();
            if (! $foreign && $connection?->canRollback()) {
                try {
                    $connection->rollbackOwned($this->authority);
                } catch (Throwable $closeError) {
                    $secondary[] = LabFailure::from($closeError, 'ROLLBACK', 'RollbackUncertain');
                }
            }
        }
        $lease?->revoke();
        $outcome = $connection?->outcome() ?? NativeOutcome::NOT_STARTED;
        if ($outcome === NativeOutcome::ACTIVE || $foreign) {
            $outcome = NativeOutcome::UNKNOWN;
        }
        $known = in_array($outcome, [NativeOutcome::NOT_STARTED, NativeOutcome::COMMITTED, NativeOutcome::ROLLED_BACK], true) && ! $foreign;
        $phase = $connection?->phase() ?? 'PREFLIGHT';
        $cleanup = $this->factory->finish($this->authority, $known, $foreign);
        $status = match (true) {
            $foreign => 'REJECTED_FOREIGN',
            $outcome === NativeOutcome::COMMITTED => 'SUCCESS_LOCAL',
            $outcome === NativeOutcome::UNKNOWN => 'UNKNOWN_LOCAL',
            $phase === 'PREFLIGHT' => 'BLOCKED_PROFILE',
            default => 'ABORTED_LOCAL',
        };
        $result = new RunResult($status, $outcome, $cleanup, $invocation->id, $count,
            $outcome === NativeOutcome::COMMITTED ? ['probe_key' => $invocation->probeKey, 'invocation_id' => $invocation->id] : null,
            $primary, $secondary, $connection?->trace() ?? [], $this->factory->fixture->directory, $phase);
        $connection = null;
        try {
            $this->factory->fixture->record('runs', $result->jsonSerialize());
        } catch (Throwable $error) {
            PrivateConnectionFactory::blockWorker();
            $failure = new LabFailure('EvidenceFailure', 'EVIDENCE', $error);
            $failure->result = $result;
            throw $failure;
        }

        return $result;
    }

    private function waitForRetry(ObservedSqliteConnection $connection, int $deadline, int $attempt): bool
    {
        $micros = min(25_000, 5_000 * $attempt);
        if (hrtime(true) + $micros * 1000 >= $deadline) {
            return false;
        }
        $connection->recordPhase('RETRY_SCHEDULED', ['wait_us' => $micros, 'deadline_ns' => $deadline]);
        usleep($micros);

        return hrtime(true) < $deadline;
    }

    public static function program(SessionLease $lease): void
    {
        FakePortA::write($lease);
        FakePortB::write($lease);
        ProbeOutbox::write($lease);
        ProbeReceipt::seal($lease);
    }
}
