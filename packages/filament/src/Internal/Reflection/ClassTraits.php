<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Reflection;

use ReflectionClass;
use ReflectionMethod;

/**
 * Traits of a loaded class do not change for the life of the process, including under Octane.
 *
 * @internal
 */
final class ClassTraits
{
    /** @var array<class-string, array<string, string>> */
    private static array $uses = [];

    /** @var array<class-string, array<class-string, array<string, bool>>> */
    private static array $methods = [];

    /** @param  object|class-string  $class */
    public static function has(object|string $class, string $trait): bool
    {
        $name = is_object($class) ? $class::class : $class;

        return in_array($trait, self::$uses[$name] ??= class_uses_recursive($name), true);
    }

    /**
     * @param  object|class-string  $class
     * @param  class-string  $trait
     * @param  list<string>  $methods
     */
    public static function keepsMethods(object|string $class, string $trait, array $methods): bool
    {
        if (! self::has($class, $trait)) {
            return false;
        }
        $name = is_object($class) ? $class::class : $class;

        foreach ($methods as $method) {
            if (! (self::$methods[$name][$trait][$method] ??= (new ReflectionMethod($name, $method))->getFileName() === (new ReflectionClass($trait))->getFileName())) {
                return false;
            }
        }

        return true;
    }
}
