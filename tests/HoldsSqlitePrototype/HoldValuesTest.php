<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\HoldsSqlite\HoldValues;

#[Group('b222-contract-values')]
final class HoldValuesTest extends TestCase
{
    public static function instants(): array
    {
        return [['2000-01-01T00:00:00.000000Z'], ['2030-01-01T08:00:00.123456Z'], ['2030-01-01T02:00:00.000001-06:00'], ['2100-12-31T23:59:59.999999Z']];
    }

    #[DataProvider('instants')]
    public function test_microseconds_round_trip_as_integers(string $text): void
    {
        $d = new DateTimeImmutable($text);
        $us = HoldValues::micros($d);
        $this->assertIsInt($us);
        $this->assertEquals($d, HoldValues::instant($us));
    }

    public static function malformed(): array
    {
        return [[null], ['1893456000123456'], [1893456000123456.0], ['1893456000123456'."\0"], [0], [-1], [PHP_INT_MAX]];
    }

    #[DataProvider('malformed')]
    public function test_stored_time_never_uses_lossy_type_conversion(mixed $value): void
    {
        $this->expectException(LogicException::class);
        HoldValues::instant($value);
    }

    public static function scopes(): array
    {
        return [['system:system:00000000-0000-4000-8000-000000000001'], ['client:901:00000000-0000-4000-8000-000000000001'], ['provider:110:00000000-0000-4000-8000-000000000001']];
    }

    #[DataProvider('scopes')]
    public function test_scope_matches_the_existing_engine_exactly(string $scope): void
    {
        [$actor,$uuid] = HoldValues::scope($scope);
        $this->assertSame($scope, $actor.':'.$uuid);
    }

    public static function badScopes(): array
    {
        return [['system::00000000-0000-4000-8000-000000000001'], ['provider:0:00000000-0000-4000-8000-000000000001'], ['provider:01:00000000-0000-4000-8000-000000000001'], ['provider:9223372036854775808:00000000-0000-4000-8000-000000000001'], ['client:901:00000000-0000-4000-8000-000000000001'."\0x"]];
    }

    #[DataProvider('badScopes')]
    public function test_malformed_scope_cannot_alias_an_existing_intention(string $scope): void
    {
        $this->expectException(LogicException::class);
        HoldValues::scope($scope);
    }
}
