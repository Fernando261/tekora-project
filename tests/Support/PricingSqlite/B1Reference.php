<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use LogicException;

/** Loads unchanged, pinned B1 sources exported outside the repository for tests only. */
final class B1Reference
{
    public static function register(): void
    {
        $root = getenv('TEKORA_B221_B1_REFERENCE');
        if (! is_string($root) || $root === '' || ! is_dir($root)) {
            throw new LogicException('Export the pinned B1 test reference and set TEKORA_B221_B1_REFERENCE.');
        }
        $manifest = json_decode(file_get_contents(__DIR__.'/b1-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $path => $hash) {
            if (! is_file($root.'/'.$path) || ! hash_equals($hash, hash_file('sha256', $root.'/'.$path))) {
                throw new LogicException('B1 reference source mismatch: '.$path);
            }
        }
        spl_autoload_register(static function (string $class) use ($root): void {
            $path = match (true) {
                str_starts_with($class, 'App\\Payments\\') => 'app/Payments/'.substr($class, strlen('App\\Payments\\')).'.php',
                $class === 'Tests\\Support\\InMemoryPricingStore' => 'tests/Support/InMemoryPricingStore.php',
                default => null,
            };
            if ($path !== null && is_file($root.'/'.$path)) {
                require_once $root.'/'.$path;
            }
        }, prepend: true);
    }
}
