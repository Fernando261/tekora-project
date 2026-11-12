<?php

declare(strict_types=1);

namespace Tests\PaymentsSqlitePrototype;

use App\Payments\PricingConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PricingSqlite\PricingValues;

final class PricingValuesTest extends TestCase
{
    public static function dates(): array
    {
        return [[null], [1], ['2030-02-30 08:00:00.123456'], ['2030-01-01 24:00:00.123456'],
            ['2030-01-01 08:00:00.12345'], ['2030-01-01 08:00:00.1234567'], ['2030-01-01T08:00:00.123456Z'],
            ['1999-12-31 23:59:59.999999'], ['2101-01-01 00:00:00.000000'], ["2030-01-01 08:00:00.123456\n"]];
    }

    #[DataProvider('dates')]
    public function test_invalid_stored_dates_are_not_silently_normalized(mixed $value): void
    {
        $this->expectException(PricingConflict::class);
        PricingValues::date($value);
    }

    public static function integers(): array
    {
        return [['1'], [1.0], [true], [null], [0], [-1], ['1suffix']];
    }

    #[DataProvider('integers')]
    public function test_stored_integer_types_are_not_coerced(mixed $value): void
    {
        $this->expectException(PricingConflict::class);
        PricingValues::integer($value);
    }

    public static function uuids(): array
    {
        return [['ABCDEFAB-ABCD-4000-8000-000000000001'], ['not-a-uuid'], [null], [1], ["abcdefab-abcd-4000-8000-000000000001\n"]];
    }

    #[DataProvider('uuids')]
    public function test_uuid_representation_is_canonical(mixed $value): void
    {
        $this->expectException(PricingConflict::class);
        PricingValues::uuid($value);
    }
}
