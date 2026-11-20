<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use LogicException;

final class B21Reference
{
    public static function register(): void
    {
        $root = getenv('TEKORA_B222_B21_REFERENCE');
        if (! is_string($root) || $root === '' || ! is_dir($root)) {
            throw new LogicException('Export the exact B2.1 reference outside the repository.');
        }
        $manifest = json_decode(file_get_contents(__DIR__.'/b21-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $path => $hash) {
            if (! is_file($root.'/'.$path) || ! hash_equals($hash, hash_file('sha256', $root.'/'.$path))) {
                throw new LogicException('B2.1 reference mismatch: '.$path);
            }
        }
        spl_autoload_register(static function (string $class) use ($root): void {
            $path = match (true) {
                str_starts_with($class, 'App\\Scheduling\\Holds\\') => 'app/Scheduling/Holds/'.substr($class, strlen('App\\Scheduling\\Holds\\')).'.php',
                in_array($class, ['Tests\\Support\\InMemorySlotHoldStore', 'Tests\\Support\\MutableSlotHoldClock'], true) => str_replace('\\', '/', lcfirst($class)).'.php',
                default => null,
            };
            if ($path !== null && is_file($root.'/'.$path)) {
                require_once $root.'/'.$path;
            }
        }, prepend: true);
    }
}
