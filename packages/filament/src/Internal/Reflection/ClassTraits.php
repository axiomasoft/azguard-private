<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Reflection;

/**
 * Traits of a loaded class do not change for the life of the process, including under Octane.
 *
 * @internal
 */
final class ClassTraits
{
    /** @var array<class-string, array<class-string, class-string>> */
    private static array $uses = [];

    /** @param  object|class-string  $class */
    public static function has(object|string $class, string $trait): bool
    {
        $name = is_object($class) ? $class::class : $class;

        return in_array($trait, self::$uses[$name] ??= class_uses_recursive($name), true);
    }
}
