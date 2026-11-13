<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use JsonSerializable;

enum NativeOutcome: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case ACTIVE = 'ACTIVE';
    case COMMITTED = 'COMMITTED';
    case ROLLED_BACK = 'ROLLED_BACK';
    case UNKNOWN = 'UNKNOWN';
}

enum FaultPoint: string
{
    case BEFORE_NATIVE_BEGIN = 'BEFORE_NATIVE_BEGIN';
    case AFTER_NATIVE_BEFORE_WITNESS = 'AFTER_NATIVE_BEFORE_WITNESS';
    case AFTER_WITNESS_BEFORE_LEVEL = 'AFTER_WITNESS_BEFORE_LEVEL';
    case AFTER_LEVEL_BEFORE_WRITE = 'AFTER_LEVEL_BEFORE_WRITE';
    case BEFORE_COORDINATION = 'BEFORE_COORDINATION';
    case BEFORE_DATA = 'BEFORE_DATA';
    case AFTER_A = 'AFTER_A';
    case AFTER_B = 'AFTER_B';
    case AFTER_OUTBOX = 'AFTER_OUTBOX';
    case AFTER_RECEIPT = 'AFTER_RECEIPT';
    case BEFORE_NATIVE_COMMIT = 'BEFORE_NATIVE_COMMIT';
    case AFTER_NATIVE_COMMIT_BEFORE_MARKER = 'AFTER_NATIVE_COMMIT_BEFORE_MARKER';
    case AFTER_COMMIT_MARKER = 'AFTER_COMMIT_MARKER';
    case BEFORE_NATIVE_ROLLBACK = 'BEFORE_NATIVE_ROLLBACK';
    case AFTER_NATIVE_ROLLBACK_BEFORE_MARKER = 'AFTER_NATIVE_ROLLBACK_BEFORE_MARKER';
    case AFTER_ROLLBACK_MARKER = 'AFTER_ROLLBACK_MARKER';
}

enum ProbeOperation: string
{
    case A = 'A';
    case B = 'B';
    case OUTBOX = 'OUTBOX';
    case RECEIPT = 'RECEIPT';
    case BROKEN_STATEMENT = 'BROKEN_STATEMENT';
}

final readonly class InvocationSpec
{
    public string $id;

    public function __construct(public string $probeKey, public string $epoch)
    {
        if (! preg_match('/\A[\x21-\x7e]{1,128}\z/D', $probeKey) || ! preg_match('/\A[a-f0-9]{32}\z/D', $epoch)) {
            throw new LabFailure('InvalidInvocation', 'PREFLIGHT');
        }
        $this->id = bin2hex(random_bytes(16));
    }
}

final readonly class RunResult implements JsonSerializable
{
    public function __construct(
        public string $localStatus,
        public NativeOutcome $nativeOutcome,
        public string $cleanupState,
        public string $invocationId,
        public int $callbackCount,
        public ?array $receiptRef,
        public ?LabFailure $primaryError,
        public array $secondaryErrors,
        public array $trace,
        public string $retainedArtifacts,
        public string $phase,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'local_status' => $this->localStatus,
            'native_outcome' => $this->nativeOutcome->value,
            'cleanup_state' => $this->cleanupState,
            'invocation_id' => $this->invocationId,
            'callback_count' => $this->callbackCount,
            'receipt_ref' => $this->receiptRef,
            'primary_error' => $this->primaryError?->jsonSerialize(),
            'secondary_errors' => array_map(fn (LabFailure $e) => $e->jsonSerialize(), $this->secondaryErrors),
            'trace' => $this->trace,
            'retained_artifacts' => $this->retainedArtifacts,
            'phase' => $this->phase,
        ];
    }
}

final readonly class ObservationResult implements JsonSerializable
{
    public function __construct(public string $kind, public string $probeKey, public string $invocationId, public string $snapshotId, public ?bool $graphValid) {}

    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind, 'probe_key' => $this->probeKey, 'invocation_id' => $this->invocationId, 'snapshot_id' => $this->snapshotId, 'graph_valid' => $this->graphValid, 'retry_permitted' => false];
    }
}
