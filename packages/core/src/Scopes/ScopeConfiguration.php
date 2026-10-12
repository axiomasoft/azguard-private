<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Support\Narrow;
use AzGuard\Panels\Internal\CanonicalMetadata;
use Closure;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionNamedType;

/** Build-time validation and scalar metadata; user components are never instantiated here. */
final class ScopeConfiguration
{
    public static function filters(AssignmentScopeDefinition $definition, ?Container $container): void
    {
        if (! $definition instanceof ConfigurableAssignmentScopeDefinition) {
            return;
        }

        $filters = $definition->settings()->filters;

        if ($filters !== [] && (! $definition instanceof QueryableAssignmentScopeDefinition || $definition->model() === null)) {
            throw new DefinitionException('Assignment scope '.$definition->type().' requires a queryable model for native filters; configure an access adapter for an external scope.');
        }

        foreach ($filters as $filter) {
            if ($filter instanceof Closure) {
                continue;
            }
            self::component($filter, AssignmentScopeFilter::class, $container, 'Assignment scope filter');
        }
        self::filterMetadata($definition);
    }

    /** @param class-string $contract */
    public static function component(mixed $component, string $contract, ?Container $container, string $label): void
    {
        if ($component instanceof $contract) {
            return;
        }

        if (! is_string($component) || ! is_a($component, $contract, true)) {
            throw new DefinitionException($label.' must implement '.$contract.'; the configured class may have been removed.');
        }

        self::resolvable($component, $container, $label, []);
    }

    /**
     * @param  class-string  $class
     * @param  list<class-string>  $seen
     */
    private static function resolvable(string $class, ?Container $container, string $label, array $seen): void
    {
        if ($container?->bound($class)) {
            return;
        }

        if (in_array($class, $seen, true)) {
            throw new DefinitionException($label.' '.$class.' has a circular constructor dependency and requires a container binding.');
        }
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new DefinitionException($label.' '.$class.' has no container binding.');
        }
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable() || $parameter->isVariadic()) {
                continue;
            }
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new DefinitionException($label.' '.$class.' requires a container binding for constructor parameter $'.$parameter->getName().'.');
            }
            $dependency = $type->getName();

            if (! class_exists($dependency) && ! interface_exists($dependency)) {
                throw new DefinitionException($label.' '.$class.' declares a missing constructor dependency '.$dependency.'.');
            }
            self::resolvable($dependency, $container, $label, [...$seen, $class]);
        }
    }

    public static function sameStructure(AssignmentScopeDefinition $registered, AssignmentScopeDefinition $binding): bool
    {
        return $registered::class === $binding::class
            && $registered->type() === $binding->type()
            && $registered->model() === $binding->model()
            && self::structure($registered) == self::structure($binding);
    }

    public static function merge(AssignmentScopeDefinition $earlier, AssignmentScopeDefinition $later): AssignmentScopeDefinition
    {
        if (! $earlier instanceof ConfigurableAssignmentScopeDefinition || ! $later instanceof ConfigurableAssignmentScopeDefinition) {
            return $later;
        }

        $merged = $earlier;
        foreach ($later->settings()->filters as $filter) {
            $merged = $merged->filter($filter);
        }

        if ($later->settings()->label !== null) {
            $merged = $merged->label($later->settings()->label);
        }

        if ($later->settings()->directory !== null) {
            return $merged->directory($later->settings()->directory);
        }

        return $merged;
    }

    /** @return array<string, mixed> */
    private static function structure(AssignmentScopeDefinition $definition): array
    {
        $properties = Narrow::map((array) $definition, 'assignment scope definition');
        foreach ($properties as $name => $value) {
            if ($value instanceof AssignmentScopeSettings || in_array($name, [
                "\0".BaseAssignmentScope::class."\0filters",
                "\0".BaseAssignmentScope::class."\0label",
                "\0".BaseAssignmentScope::class."\0directory",
            ], true)) {
                unset($properties[$name]);
            }
        }

        return $properties;
    }

    /** @return list<string> */
    public static function filterMetadata(AssignmentScopeDefinition $definition): array
    {
        if (! $definition instanceof ConfigurableAssignmentScopeDefinition) {
            return [];
        }

        return array_map(static fn (mixed $filter): string => hash('sha256', json_encode(CanonicalMetadata::componentMetadata($filter), JSON_THROW_ON_ERROR)), $definition->settings()->filters);
    }
}
