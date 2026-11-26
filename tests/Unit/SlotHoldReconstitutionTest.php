<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('slot-holds-b21')]
final class SlotHoldReconstitutionTest extends TestCase
{
    private function original(): SlotHold
    {
        $start = new DateTimeImmutable('2030-01-01T10:00:00.000001Z');
        $proposal = new ReservationProposal(1, 7, new UtcInterval($start, $start->modify('+1 hour')),
            new AcceptedQuoteReference('quote-1', 2, 3, 4, 5, 6));

        return SlotHold::start($proposal, new DateTimeImmutable('2030-01-01T08:00:00.123456Z'));
    }

    public static function states(): array
    {
        return [[HoldState::Held], [HoldState::Committed], [HoldState::Released], [HoldState::Expired]];
    }

    #[DataProvider('states')]
    public function test_reconstitution_preserves_identity_deadline_version_and_state(HoldState $state): void
    {
        $hold = $this->original();
        if ($state !== HoldState::Held) {
            $hold = $hold->transition($state, $state === HoldState::Expired ? $hold->expiresAt : $hold->acceptedAt->modify('+899 seconds'));
        }
        $restored = SlotHold::fromStorage($hold->id, $hold->proposal, $hold->state, $hold->version,
            $hold->acceptedAt, $hold->expiresAt, $hold->updatedAt);
        $this->assertNotSame($hold, $restored);
        $this->assertEquals($hold, $restored);
        $this->assertSame($hold->id, $restored->id);
        $this->assertSame($hold->proposal->fingerprint(), $restored->proposal->fingerprint());
    }

    public static function corruptRecords(): array
    {
        return [['bad_id'], ['upper_id'], ['extended_deadline'], ['short_deadline'], ['held_version'],
            ['terminal_version'], ['held_updated'], ['expired_early'], ['committed_late'], ['released_before_acceptance']];
    }

    #[DataProvider('corruptRecords')]
    public function test_invalid_stored_lifecycle_cannot_be_silently_normalized(string $variant): void
    {
        $hold = $this->original();
        $id = match ($variant) {
            'bad_id' => 'not-a-hold-id',
            'upper_id' => 'ABCDEFAB-ABCD-4000-8000-ABCDEFABCDEF',
            default => $hold->id,
        };
        $state = match ($variant) {
            'terminal_version', 'committed_late' => HoldState::Committed,
            'expired_early' => HoldState::Expired,
            'released_before_acceptance' => HoldState::Released,
            default => HoldState::Held,
        };
        $version = $state === HoldState::Held ? 1 : 2;
        if ($variant === 'held_version') {
            $version = 2;
        }
        if ($variant === 'terminal_version') {
            $version = 1;
        }
        $expires = match ($variant) {
            'extended_deadline' => $hold->expiresAt->modify('+1 microsecond'),
            'short_deadline' => $hold->expiresAt->modify('-1 microsecond'),
            default => $hold->expiresAt,
        };
        $updated = match ($variant) {
            'held_updated' => $hold->acceptedAt->modify('+1 second'),
            'expired_early' => $hold->expiresAt->modify('-1 microsecond'),
            'committed_late' => $hold->expiresAt,
            'released_before_acceptance' => $hold->acceptedAt->modify('-1 microsecond'),
            default => $hold->acceptedAt,
        };
        $this->expectException(\InvalidArgumentException::class);
        SlotHold::fromStorage($id, $hold->proposal, $state, $version, $hold->acceptedAt, $expires, $updated);
    }
}
