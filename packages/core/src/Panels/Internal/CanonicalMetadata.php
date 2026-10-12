<?php

declare(strict_types=1);

namespace AzGuard\Panels\Internal;

use AzGuard\Exceptions\DefinitionException;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionFunction;
use UnitEnum;

/**
 * @internal Canonical fingerprint metadata of declarations and option objects: stable configuration only. Live models,
 * queries, requests and containers are refused; nothing is instantiated here.
 */
final class CanonicalMetadata
{
    /**
     * An option object holds plain configuration: no live model, query, request or container (refused by
     * {@see metadata()}) and no closure bound to an object.
     *
     * @throws DefinitionException
     */
    public static function assertOptions(object $options): void
    {
        self::metadata($options);
        self::scanForBoundClosures($options, []);
    }

    /** @param list<object> $seen */
    private static function scanForBoundClosures(mixed $value, array $seen): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::scanForBoundClosures($item, $seen);
            }

            return;
        }

        if (! is_object($value) || $value instanceof UnitEnum || in_array($value, $seen, true)) {
            return;
        }
        $seen[] = $value;

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);

            if ($reflection->getClosureThis() !== null) {
                throw new DefinitionException('An option object cannot hold a closure bound to an object; use a static closure or a class name.');
            }
            self::scanForBoundClosures($reflection->getStaticVariables(), $seen);

            return;
        }

        foreach ((array) $value as $item) {
            self::scanForBoundClosures($item, $seen);
        }
    }

    public static function componentMetadata(mixed $component): mixed
    {
        $metadata = self::metadata($component);
        $class = is_object($component) && ! $component instanceof Closure ? $component::class : $component;

        if (is_string($class) && (class_exists($class) || interface_exists($class))) {
            $file = (new ReflectionClass($class))->getFileName();

            return ['value' => $metadata, 'source' => $file === false ? null : hash_file('sha256', $file)];
        }

        return $metadata;
    }

    /**
     * Stable configuration only: live models never contribute attributes or object ids.
     *
     * @param  list<object>  $seen
     */
    public static function metadata(mixed $value, array $seen = []): mixed
    {
        if ($value instanceof Model || $value instanceof Builder || $value instanceof QueryBuilder || $value instanceof Request || $value instanceof Container) {
            throw new DefinitionException('A declaration or option object cannot capture a live model, query, request or container; obtain operation inputs when the operation runs.');
        }

        if ($value instanceof UnitEnum) {
            return ['enum' => $value::class, 'case' => $value->name];
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(static fn (mixed $item): mixed => self::metadata($item, $seen), $value);
        }

        if (! is_object($value)) {
            return is_resource($value) ? get_resource_type($value) : $value;
        }

        if (in_array($value, $seen, true)) {
            return ['class' => $value::class];
        }
        $seen[] = $value;

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            $bound = $reflection->getClosureThis();

            if ($bound instanceof Model || $bound instanceof Builder || $bound instanceof QueryBuilder || $bound instanceof Request || $bound instanceof Container) {
                throw new DefinitionException('A declaration or option object cannot bind a closure to a live model, query, request or container.');
            }

            return ['closure' => [$reflection->getFileName(), $reflection->getStartLine(), $reflection->getEndLine()],
                'captures' => self::metadata($reflection->getStaticVariables(), $seen)];
        }

        return ['class' => $value::class, 'configuration' => self::metadata((array) $value, $seen)];
    }
}
