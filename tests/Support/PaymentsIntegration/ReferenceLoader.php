<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use LogicException;
use ReflectionClass;

final class ReferenceLoader
{
    public static function register(string $root): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/references.json'), true, flags: JSON_THROW_ON_ERROR);
        $classes = [];
        foreach ($manifest as $ref => $entry) {
            foreach ($entry['files'] as $name => $hash) {
                $file = $root.'/'.$ref.'/'.$name;
                if (is_link($file) || ! is_file($file) || hash_file('sha256', $file) !== $hash) {
                    throw new LogicException('Missing or altered exact reference: '.$ref.'/'.$name);
                }
                $class = match (true) {
                    str_starts_with($name, 'app/') => 'App\\'.str_replace('/', '\\', substr($name, 4, -4)),
                    $ref === 'pr34' && $name === 'tests/Support/PricingSqlite/PricingValues.php' => 'Tests\\Support\\PricingSqlite\\PricingValues',
                    $ref === 'pr35' && $name === 'tests/Support/HoldsSqlite/HoldValues.php' => 'Tests\\Support\\HoldsSqlite\\HoldValues',
                    default => null,
                };
                if ($class !== null && str_ends_with($name, '.php')) {
                    if (isset($classes[$class])) {
                        throw new LogicException('Duplicate canonical definition');
                    }
                    $classes[$class] = realpath($file);
                }
            }
        }
        $loader = static function (string $class) use ($classes): void {
            if (isset($classes[$class])) {
                require_once $classes[$class];
            }
        };
        spl_autoload_register($loader, true, true);
        foreach ($classes as $class => $file) {
            if (! (class_exists($class) || interface_exists($class) || enum_exists($class)) || (new ReflectionClass($class))->getFileName() !== $file) {
                throw new LogicException('Noncanonical class origin: '.$class);
            }
        }
    }
}
