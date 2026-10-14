<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\AssignmentScopeSettings;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Contracts\ConfigurableAssignmentScopeDefinition;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use UnexpectedValueException;

/**
 * Build-time validation of filters, access adapters and role bindings, and the reader of the scope options; user
 * components are never instantiated here. Core reads definitions only through their description.
 */
final class ScopeConfiguration
{
    /** @return AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null */
    public static function membership(Panel $panel): AssignmentScopeMembership|string|null
    {
        return $panel->options(ScopesOptions::class)?->membership;
    }

    /** @return array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>> */
    public static function adapters(Panel $panel): array
    {
        return $panel->options(ScopesOptions::class)->adapters ?? [];
    }

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
                self::closure($definition->type(), $filter);

                continue;
            }
            self::component($filter, AssignmentScopeFilter::class, $container, 'Assignment scope filter');
        }
        // A filter that captures a live model, query, request or container is refused by core when it fingerprints the
        // description's components (panel definitions) and hashes them (role bindings).
    }

    /**
     * A closure filter is called like `AssignmentScopeFilter::apply($query, $runtime)`: at most two required
     * parameters, the first accepting the Eloquent builder and the second the runtime (an untyped one accepts both).
     *
     * @throws DefinitionException
     */
    private static function closure(string $type, Closure $filter): void
    {
        $reflection = new ReflectionFunction($filter);
        $parameters = $reflection->getParameters();
        $accepted = $reflection->getNumberOfRequiredParameters() <= 2
            && self::accepts($parameters[0] ?? null, Builder::class)
            && self::accepts($parameters[1] ?? null, AssignmentScopeRuntime::class);

        if (! $accepted) {
            throw new DefinitionException('Assignment scope '.$type.' has a filter closure that cannot be called as ($query, $runtime): declare '
                .'function (Builder $query, AssignmentScopeRuntime $runtime) and read the user, role, grant, actor, scope, now, phase and '
                .'proposed values from the runtime.');
        }
    }

    /** @param class-string $class */
    private static function accepts(?ReflectionParameter $parameter, string $class): bool
    {
        return $parameter === null || self::admits($parameter->getType(), $class);
    }

    /** @param class-string $class */
    private static function admits(?ReflectionType $type, string $class): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            return in_array($type->getName(), ['mixed', 'object'], true) || (! $type->isBuiltin() && is_a($class, $type->getName(), true));
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if (self::admits($part, $class)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if (! self::admits($part, $class)) {
                    return false;
                }
            }

            return true;
        }

        return false;
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

    /** @return array<string, mixed> */
    private static function structure(AssignmentScopeDefinition $definition): array
    {
        $properties = [];
        foreach ((array) $definition as $name => $value) {
            if (! is_string($name)) {
                throw new UnexpectedValueException('assignment scope definition key must be a string, got '.get_debug_type($name).'.');
            }
            $properties[$name] = $value;
        }
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
}
