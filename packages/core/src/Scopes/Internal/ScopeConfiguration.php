<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Support\Narrow;
use AzGuard\Panels\Internal\CanonicalMetadata;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\AssignmentScopeSettings;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Tenancy\TenantMode;
use Closure;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Build-time validation and scalar metadata; user components are never instantiated here. Also the reader of the
 * scope options for the call sites outside the scope code.
 */
final class ScopeConfiguration
{
    /**
     * A tenant panel cannot use a model-backed definition without an owner callback: its scopes would all belong to
     * the global tenant.
     *
     * @throws InvalidConfigurationException
     */
    public static function tenantOwner(string $panel, AssignmentScopeDefinition $definition, TenantMode $tenants): void
    {
        if ($tenants === TenantMode::Required && $definition instanceof ModelAssignmentScopeDefinition && ! $definition->hasOwner()) {
            throw InvalidConfigurationException::failing('tenant_scope', 'Tenant panel '.$panel.' requires an authoritative tenant owner for assignment scope '
                .$definition->type().': give the model definition an explicit owner callback.');
        }
    }

    /**
     * The membership adapter can be created and every access adapter is resolvable, holds plain configuration and
     * belongs to a declared scope type.
     *
     * @param  array<class-string, object>  $options  the module options of the panel
     * @param  list<string>  $types  the declared scope type aliases
     *
     * @throws DefinitionException
     * @throws InvalidConfigurationException
     */
    public static function checkOptions(string $panel, array $options, array $types, ?Container $container): void
    {
        $scopes = $options[ScopesOptions::class] ?? null;

        if (! $scopes instanceof ScopesOptions) {
            return;
        }
        $membership = $scopes->membership;

        if (is_string($membership) && ! ($container?->bound($membership) ?? false) && ! (new ReflectionClass($membership))->isInstantiable()) {
            throw InvalidConfigurationException::failing('membership', 'Assignment scope membership adapter '.$membership.' has no container binding: bind it or name a class that can be created.');
        }

        foreach ($scopes->adapters as $type => $adapter) {
            self::component($adapter, AssignmentScopeAccessAdapter::class, $container, 'Assignment scope access adapter');
            CanonicalMetadata::componentMetadata($adapter);

            if (! in_array($type, $types, true)) {
                throw new DefinitionException('Panel '.$panel.' registers an access adapter for unknown assignment scope '.$type.'.');
            }
        }
    }

    /** A model-backed definition without an owner callback: every record belongs to the global tenant. */
    public static function ownerless(AssignmentScopeDefinition $definition): bool
    {
        return $definition instanceof ModelAssignmentScopeDefinition && ! $definition->hasOwner();
    }

    /** Whether `resolve()` is the base one, equal to `query()` plus `tenantOf()`; a custom one may refuse a record. */
    public static function resolvesByQuery(AssignmentScopeDefinition $definition): bool
    {
        return (new ReflectionMethod($definition, 'resolve'))->getDeclaringClass()->getName() === BaseAssignmentScope::class;
    }

    /** @return class-string<AssignmentScopeDirectory>|null the directory a configurable definition names */
    public static function directory(AssignmentScopeDefinition $definition): ?string
    {
        return $definition instanceof ConfigurableAssignmentScopeDefinition ? $definition->settings()->directory : null;
    }

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
