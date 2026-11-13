<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

final class ProbeReceipt
{
    public static function seal(SessionLease $lease): void
    {
        $lease->write(ProbeOperation::RECEIPT);
    }

    public static function observe(LabFixture $fixture, InvocationSpec $invocation, bool $known = false, ?string $fault = null): ObservationResult
    {
        $snapshot = $fixture->observe($known ? 'S' : 'U', $known, $fault);
        if (! $snapshot['read_complete']) {
            throw new LabFailure('ObservationUnavailable', 'OBSERVE', $fixture->observationError());
        }
        if ($snapshot['structural_status'] !== 'PASS' || $snapshot['functional_status'] !== 'PASS') {
            throw new LabFailure('ReceiptMismatch', 'OBSERVE');
        }
        $found = array_values(array_filter($snapshot['tables']['probe_receipt'], fn ($row) => $row['probe_key'] === $invocation->probeKey && $row['invocation_id'] === $invocation->id));

        return new ObservationResult($found === [] ? 'NO_RECEIPT_OBSERVED' : 'RECEIPT_OBSERVED', $invocation->probeKey, $invocation->id, $snapshot['snapshot_id'], $found === [] ? null : true);
    }
}
