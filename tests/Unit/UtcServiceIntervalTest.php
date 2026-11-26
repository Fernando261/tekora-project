<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldTime;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SystemHoldClock;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('slot-holds-b21')]
final class UtcServiceIntervalTest extends TestCase
{
    public function test_offsets_are_normalized_to_utc_without_changing_instants_or_microseconds(): void
    {
        $interval = new UtcInterval(new DateTimeImmutable('2030-01-01T10:00:00.123456-06:00'), new DateTimeImmutable('2030-01-01T11:00:00.123456-06:00'));
        $this->assertSame('2030-01-01T16:00:00.123456Z', HoldTime::text($interval->start));
        $this->assertSame('2030-01-01T17:00:00.123456Z', HoldTime::text($interval->end));
        $this->assertSame('UTC', $interval->start->getTimezone()->getName());
    }

    public static function invalidLengths(): array
    {
        return [['-1 second'], ['+0 seconds'], ['+3599 seconds'], ['+3601 seconds'], ['+3600 seconds +1 microsecond']];
    }

    #[DataProvider('invalidLengths')]
    public function test_non_sixty_minute_or_reversed_intervals_are_rejected(string $offset): void
    {
        $start = new DateTimeImmutable('2030-01-01T10:00:00.000000Z');
        $this->expectException(\InvalidArgumentException::class);
        new UtcInterval($start, $start->modify($offset));
    }

    public function test_initial_inquiry_cannot_replace_a_concrete_proposal_or_quote(): void
    {
        $start = new DateTimeImmutable('2030-01-01T10:00:00Z');
        $quote = new AcceptedQuoteReference('q', 1, 2, 3, 4, 5);
        $this->expectException(\InvalidArgumentException::class);
        new ReservationProposal(0, 1, new UtcInterval($start, $start->modify('+1 hour')), $quote);
    }

    public function test_own_service_cannot_be_a_synthetic_client_quote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AcceptedQuoteReference('q', 1, 2, 3, 4, 4);
    }

    public function test_interval_normalization_copies_native_values_instead_of_retaining_a_subclass(): void
    {
        $start = new class('2030-01-01T10:00:00Z') extends DateTimeImmutable {};
        $interval = new UtcInterval($start, $start->modify('+1 hour'));
        $this->assertSame(DateTimeImmutable::class, $interval->start::class);
        $this->assertNotSame($start, $interval->start);
    }

    public function test_real_clock_implementation_is_utc_but_does_not_retain_slots(): void
    {
        $now = (new SystemHoldClock)->now();
        $this->assertSame('UTC', $now->getTimezone()->getName());
    }
}
