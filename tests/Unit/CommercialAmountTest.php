<?php

namespace Tests\Unit;

use App\Payments\CommercialAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('payments-b1')]
class CommercialAmountTest extends TestCase
{
    public static function amounts(): array
    {
        return [[1, 0, 1], [3, 0, 3], [4, 1, 3], [9, 1, 8], [10, 2, 8],
            [11, 2, 9], [20, 3, 17], [30, 5, 25], [50, 8, 42], [99, 15, 84],
            [100, 15, 85], [101, 15, 86], [100_000, 15_000, 85_000], [1_000_000_000, 150_000_000, 850_000_000]];
    }

    #[DataProvider('amounts')]
    public function test_exact_commercial_split_and_half_up_rounding(int $total, int $commission, int $provider): void
    {
        $money = CommercialAmount::fromCents($total);
        $this->assertSame([$total, $commission, $provider], [$money->totalCents, $money->commissionCents, $money->providerCents]);
        $this->assertSame($total, $money->commissionCents + $money->providerCents);
        $this->assertSame('MXN', $money->currency);
        $this->assertSame(60, $money->durationMinutes);
        $this->assertSame([1500, 8500], [$money->commissionBasisPoints, $money->providerBasisPoints]);
        $this->assertSame(CommercialAmount::POLICY, $money->policyVersion);
        $this->assertEquals($money, CommercialAmount::fromCents((string) $total));
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], [0, -1, 1_000_000_001, PHP_INT_MAX, PHP_INT_MIN, 1.0, 1.5, INF, NAN,
            true, false, null, [], '', '0', '-1', '+1', '01', ' 1', '1 ', '1.0', '1e2', '1000000001', '999999999999999999999', "1\n", '١', new \stdClass]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_lossy_representations_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommercialAmount::fromCents($value);
    }

    public function test_rounding_error_is_bounded_and_totals_never_gain_a_cent(): void
    {
        for ($total = 1; $total <= 1000; $total++) {
            $money = CommercialAmount::fromCents($total);
            $error = 100 * $money->commissionCents - 15 * $total;
            $this->assertGreaterThan(-50, $error);
            $this->assertLessThanOrEqual(50, $error);
            $this->assertSame($total, $money->commissionCents + $money->providerCents);
        }
    }

    public function test_commercial_amount_is_readonly(): void
    {
        $money = CommercialAmount::fromCents(100);
        $this->expectException(\Error::class);
        $money->commissionCents = 0;
    }
}
