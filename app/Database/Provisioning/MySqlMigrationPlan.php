<?php

namespace App\Database\Provisioning;

use LogicException;

final class MySqlMigrationPlan
{
    public static function files(): array
    {
        $originals = glob(database_path('migrations/*.php'));
        $variants = glob(database_path('mysql/migrations/*.php'));
        $shared = array_combine(array_map('basename', $originals), $originals);
        $overrides = array_combine(array_map('basename', $variants), $variants);
        if (count($shared) !== 39 || count($overrides) !== 9 || array_diff_key($overrides, $shared)) {
            throw new LogicException('Revisar las 39 identidades y sus nueve variantes MySQL.');
        }
        $files = array_replace($shared, $overrides);
        ksort($files, SORT_STRING);

        return array_values($files);
    }

    public static function extensions(): array
    {
        return [database_path('mysql/extensions/2026_11_26_000000_create_integrated_checkout.php')];
    }

    private static function forVersion(bool $checkout): array
    {
        return $checkout ? [...self::files(), ...self::extensions()] : self::files();
    }

    public static function identities(bool $checkout = false): array
    {
        return array_map(fn (string $path) => basename($path, '.php'), self::forVersion($checkout));
    }

    public static function digest(bool $checkout = false): string
    {
        $hashes = array_map(fn (string $path) => [basename($path), hash_file('sha256', $path)], self::forVersion($checkout));
        $hashes[] = ['ServiceCategorySeeder.php', hash_file('sha256', database_path('seeders/ServiceCategorySeeder.php'))];

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
