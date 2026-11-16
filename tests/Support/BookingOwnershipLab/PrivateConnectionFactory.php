<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use PDO;
use WeakReference;

final class PrivateConnectionFactory
{
    /** Process-root custody, deliberately independent of invocation stack lifetime. */
    private static array $workerFactories = [];

    private static ?int $workerPid = null;

    private static bool $admissionClosed = false;

    private readonly object $authority;

    private ?PDO $pdo = null;

    private ?ObservedSqliteConnection $connection = null;

    private ?WeakReference $weakPdo = null;

    private ?WeakReference $weakConnection = null;

    private bool $issued = false;

    private string $cleanup = 'not_opened';

    public readonly FaultPlan $faults;

    public function __construct(public readonly LabFixture $fixture, ?FaultPlan $faults = null)
    {
        self::assertAdmission();
        $this->authority = new \stdClass;
        $this->faults = $faults ?? new FaultPlan;
        self::$workerFactories[] = $this;
    }

    public static function assertAdmission(): void
    {
        if (getenv('T1_ISOLATED') !== '1') {
            throw new LabFailure('IsolationRequired', 'PREFLIGHT');
        }
        self::$workerPid ??= getmypid();
        if (self::$workerPid !== getmypid()) {
            throw new LabFailure('ProcessMismatch', 'PREFLIGHT');
        }
        if (self::$admissionClosed) {
            throw new LabFailure('ConnectionInvalidated', 'DRAINING_QUARANTINED');
        }
    }

    public static function blockWorker(): void
    {
        self::$admissionClosed = true;
    }

    public static function admissionClosed(): bool
    {
        return self::$admissionClosed;
    }

    public static function rejectBorrowed(PDO $pdo): never
    {
        // Do not retain, mutate, close or inspect a borrowed transaction.
        throw new LabFailure('ForeignHandleRejected', 'PREFLIGHT');
    }

    public function coordinator(): OwnershipCoordinator
    {
        self::assertAdmission();
        if ($this->issued) {
            throw new LabFailure('ConnectionInvalidated', 'PREFLIGHT');
        }
        $this->issued = true;

        return new OwnershipCoordinator($this, $this->authority);
    }

    public function open(object $authority): ObservedSqliteConnection
    {
        $this->checkAuthority($authority);
        self::assertAdmission();
        if ($this->pdo !== null || $this->cleanup !== 'not_opened') {
            throw new LabFailure('ConnectionInvalidated', 'PREFLIGHT');
        }
        try {
            $this->pdo = $this->fixture->connect(false);
            $this->weakPdo = WeakReference::create($this->pdo);
            $this->connection = new ObservedSqliteConnection($this->pdo, $this->fixture, $this->faults, $this->authority);
            $this->weakConnection = WeakReference::create($this->connection);
            $this->cleanup = 'owned_registered';
            if ($this->faults->profileProbe !== null) {
                $this->connection->harnessProfileProbe($authority, $this->faults->profileProbe);
            }
            $this->faults->attachForeign($this->pdo);
            if ($this->faults->foreignMode === 'level') {
                $this->connection->harnessForeignLevel($authority);
            }

            return $this->connection;
        } catch (\Throwable $error) {
            // Construction/configuration cannot run BEGIN or framework hooks.
            if ($this->faults->foreignMode !== null && $this->pdo !== null) {
                $this->cleanup = 'retained_foreign';
                self::blockWorker();
            } else {
                $this->connection = null;
                $this->pdo = null;
                $this->cleanup = 'idle_released';
            }
            throw $error;
        }
    }

    public function finish(object $authority, bool $known, bool $foreign = false): string
    {
        $this->checkAuthority($authority);
        if ($this->connection === null) {
            return $this->cleanup;
        }
        if (! $known || $foreign) {
            self::blockWorker();
            $this->connection->invalidate($authority);
            $this->cleanup = $foreign ? 'retained_foreign' : 'retained_quarantine';
        } else {
            $this->connection->releaseKnown($authority);
            $this->connection = null;
            $this->pdo = null;
            $this->cleanup = 'release_requested';
        }

        return $this->cleanup;
    }

    /** Explicit action by the negative-test caller after its own proven rollback. */
    public function releaseAfterCaller(): void
    {
        if ($this->cleanup !== 'retained_foreign' || ! $this->faults->callerCloseProven()) {
            throw new LabFailure('CallerCloseNotProven', 'CALLER');
        }
        $this->connection?->releaseAfterCaller($this->authority);
        $this->connection = null;
        $this->pdo = null;
        $this->cleanup = 'release_requested';
    }

    public function weakConnection(): ?WeakReference
    {
        return $this->weakConnection;
    }

    public function weakPdo(): ?WeakReference
    {
        return $this->weakPdo;
    }

    public function auditRelease(): array
    {
        gc_collect_cycles();
        $pdoAlive = $this->weakPdo?->get() !== null;
        $connectionAlive = $this->weakConnection?->get() !== null;
        $descriptors = [];
        foreach (scandir('/proc/self/fd') as $fd) {
            $target = @readlink('/proc/self/fd/'.$fd);
            if (is_string($target) && ($target === $this->fixture->path() || str_starts_with($target, $this->fixture->path().'-'))) {
                $descriptors[] = (int) $fd;
            }
        }
        $retained = in_array($this->cleanup, ['retained_quarantine', 'retained_foreign'], true);
        $status = $retained ? 'RETAINED_UNKNOWN' : (! $pdoAlive && ! $connectionAlive && $descriptors === [] ? 'RELEASED' : 'RELEASE_UNVERIFIED');
        if ($status === 'RELEASE_UNVERIFIED') {
            self::blockWorker();
            $this->cleanup = 'release_unverified';
        }
        $evidence = ['status' => $status, 'cleanup' => $this->cleanup, 'pdo_alive' => $pdoAlive, 'connection_alive' => $connectionAlive,
            'sqlite_fds' => $descriptors, 'admission_closed' => self::$admissionClosed, 'pid' => getmypid()];
        $this->fixture->record('release', $evidence);

        return $evidence;
    }

    private function checkAuthority(object $authority): void
    {
        if ($authority !== $this->authority) {
            throw new LabFailure('WitnessMissing', 'AUTHORITY');
        }
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
