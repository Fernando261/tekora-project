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

    public const CURRENT = 3;

    public static function trust(): array
    {
        return [database_path('extensions/2026_11_26_000001_create_professional_trust_and_locations.php')];
    }

    private static function forVersion(int $version): array
    {
        return [...self::files(), ...($version >= 2 ? self::extensions() : []), ...($version >= 3 ? self::trust() : [])];
    }

    public static function identities(int $version = 1): array
    {
        return array_map(fn (string $path) => basename($path, '.php'), self::forVersion($version));
    }

    public static function digest(int $version = 1): string
    {
        $hashes = array_map(fn (string $path) => [basename($path), hash_file('sha256', $path)], self::forVersion($version));
        $hashes[] = ['ServiceCategorySeeder.php', hash_file('sha256', database_path('seeders/ServiceCategorySeeder.php'))];

        if ($version >= 3) {
            $hashes[] = ['Municipalities.php', hash_file('sha256', app_path('Support/Municipalities.php'))];
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
