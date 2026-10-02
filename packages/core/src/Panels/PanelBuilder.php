<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Roles\BaseRole;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use ReflectionEnum;
use UnitEnum;

/**
 * Describes a panel inside `PanelProvider::panel()` and in `configure` callbacks.
 *
 * Every call is written to the panel recipe together with its origin; the builder exposes nothing to read back.
 * Once the panel is compiled the builder is sealed and any further call throws `RegistryFrozenException`.
 */
final class PanelBuilder
{
    /**
     * @internal built by the panel registry
     */
    public function __construct(private readonly PanelRecipe $recipe) {}

    /**
     * Optional: the id always comes from `PanelProvider::getId()` and a different value is rejected.
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function id(string $id): static
    {
        if ($id !== $this->recipe->panelId()) {
            throw new DefinitionException(
                'Panel "'.$this->recipe->panelId().'" cannot take the id "'.$id.'": the id is the value of getId() of its provider.',
            );
        }

        return $this->record(PanelRecipe::ID, $id);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function label(string $label): static
    {
        return $this->record(PanelRecipe::LABEL, $label);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function description(?string $description): static
    {
        return $this->record(PanelRecipe::DESCRIPTION, $description);
    }

    /**
     * Makes the panel the default one for its subject models.
     *
     * @throws RegistryFrozenException
     */
    public function default(bool $default = true): static
    {
        return $this->record(PanelRecipe::DEFAULT, $default);
    }

    /**
     * Prefix of permission names of the panel: `true` is the panel id, a string is one custom segment, `false`
     * turns the prefix off.
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function resourcePrefix(string|bool $prefix = true): static
    {
        if (is_string($prefix) && ! PermissionGrammar::isSegment($prefix)) {
            throw $this->invalid('resourcePrefix() expects one name segment such as "backoffice", got '.self::describe($prefix));
        }

        return $this->record(PanelRecipe::RESOURCE_PREFIX, $prefix);
    }

    /**
     * Declares subject models of the panel; repeated calls add models.
     *
     * @param  class-string<Model>|list<class-string<Model>>  $model
     * @param  string|null  $guard  Laravel auth guard the current subject is taken from; it is not a panel id
     * @param  string|null  $directory  class of the directory that looks subjects up for interfaces
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function for(string|array $model, ?string $guard = null, ?string $directory = null): static
    {
        $models = self::input(is_array($model) ? $model : [$model]);

        if ($models === [] || ! array_is_list($models)) {
            throw $this->invalid('for() expects a model class or a non-empty list of model classes');
        }

        if ($guard === '' || $directory === '') {
            throw $this->invalid('for() expects a non-empty guard and directory when they are given');
        }

        $subjects = [];

        foreach ($models as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw $this->invalid('for() expects Eloquent model classes, got '.self::describe($class));
            }

            $subjects[] = ['model' => ltrim($class, '\\'), 'guard' => $guard, 'directory' => $directory];
        }

        return $this->record(PanelRecipe::SUBJECTS, $subjects);
    }

    /**
     * Middleware that runs when a request enters the panel; repeated calls add middleware.
     *
     * @param  list<string>  $middleware
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function middleware(array $middleware): static
    {
        $names = [];

        foreach (self::input($middleware) as $name) {
            if (! is_string($name) || $name === '') {
                throw $this->invalid('middleware() expects middleware names or classes, got '.self::describe($name));
            }

            $names[] = $name;
        }

        return $this->record(PanelRecipe::MIDDLEWARE, $names);
    }

    /**
     * Permission required to enter the panel in addition to being its subject; `null` removes the requirement.
     *
     * @throws RegistryFrozenException
     */
    public function entry(string|UnitEnum|null $permission): static
    {
        return $this->record(PanelRecipe::ENTRY, $permission);
    }

    /**
     * Response to a denied entry: a closure or a redirect target; `null` keeps the 403 response.
     *
     * @throws RegistryFrozenException
     */
    public function onDenied(Closure|string|null $response): static
    {
        return $this->record(PanelRecipe::ON_DENIED, $response);
    }

    /**
     * Strict mode: every route action of the panel must declare a permission check or opt out explicitly.
     *
     * @throws RegistryFrozenException
     */
    public function requireRouteChecks(): static
    {
        return $this->record(PanelRecipe::REQUIRE_ROUTE_CHECKS, true);
    }

    /**
     * Adds permission definitions: string-backed enum classes, source objects and names of registered sources.
     *
     * A string is never a permission name here: it is an enum class or the name of a registered source. Repeated
     * calls add definitions and the same enum class is kept once.
     *
     * @param  list<Source|class-string<BackedEnum>|string>  $definitions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function permissions(array $definitions): static
    {
        return $this->record(PanelRecipe::PERMISSIONS, array_map($this->definition(...), array_values(self::input($definitions))));
    }

    /**
     * Adds code roles to the panel; the same class is kept once.
     *
     * @param  list<class-string<BaseRole>>  $roles
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function roles(array $roles): static
    {
        $classes = [];

        foreach (self::input($roles) as $role) {
            if (! is_string($role) || ! is_subclass_of($role, BaseRole::class)) {
                throw $this->invalid('roles() expects classes that extend '.BaseRole::class.', got '.self::describe($role));
            }

            $classes[] = ltrim($role, '\\');
        }

        return $this->record(PanelRecipe::ROLES, $classes);
    }

    /**
     * Groups, icons and labels for interfaces; the options never affect a decision.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws RegistryFrozenException
     */
    public function presentation(array $options): static
    {
        return $this->record(PanelRecipe::PRESENTATION, $options);
    }

    /**
     * Adds hooks that run before the sources and may short-circuit the decision.
     *
     * @param  array<mixed>|Closure|string  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function before(array|Closure|string $hooks): static
    {
        return $this->hooks(PanelRecipe::BEFORE, 'before', is_array($hooks) ? $hooks : [$hooks]);
    }

    /**
     * Adds restrictions; a restriction can only forbid.
     *
     * @param  array<mixed>  $restrictions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function restrictions(array $restrictions): static
    {
        return $this->hooks(PanelRecipe::RESTRICTIONS, 'restrictions', $restrictions);
    }

    /**
     * Adds hooks that observe a finished decision.
     *
     * @param  array<mixed>|Closure|string  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function after(array|Closure|string $hooks): static
    {
        return $this->hooks(PanelRecipe::AFTER, 'after', is_array($hooks) ? $hooks : [$hooks]);
    }

    /**
     * Adds pipes every change of grants passes through.
     *
     * @param  array<mixed>  $pipes
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function changing(array $pipes): static
    {
        return $this->hooks(PanelRecipe::CHANGING, 'changing', $pipes);
    }

    /**
     * Adds conditions a grant must meet to apply; conditions of one grant are joined with AND.
     *
     * @param  array<mixed>  $conditions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function grantConditions(array $conditions): static
    {
        return $this->hooks(PanelRecipe::GRANT_CONDITIONS, 'grantConditions', $conditions);
    }

    /**
     * Adds health checks of the panel.
     *
     * @param  array<mixed>  $checks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function doctorChecks(array $checks): static
    {
        return $this->hooks(PanelRecipe::DOCTOR_CHECKS, 'doctorChecks', $checks);
    }

    /**
     * Adds resolvers of the current tenant, tried in the order they were added.
     *
     * @param  list<TenantResolver|class-string<TenantResolver>>  $resolvers
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function tenantResolvers(array $resolvers): static
    {
        return $this->record(
            PanelRecipe::TENANT_RESOLVERS,
            array_map(fn (mixed $resolver): object|string => $this->resolver('tenantResolvers', $resolver, TenantResolver::class), array_values(self::input($resolvers))),
        );
    }

    /**
     * Adds resolvers of the current assignment scope, tried in the order they were added.
     *
     * @param  list<AssignmentScopeResolver|class-string<AssignmentScopeResolver>>  $resolvers
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function scopeResolvers(array $resolvers): static
    {
        return $this->record(
            PanelRecipe::SCOPE_RESOLVERS,
            array_map(fn (mixed $resolver): object|string => $this->resolver('scopeResolvers', $resolver, AssignmentScopeResolver::class), array_values(self::input($resolvers))),
        );
    }

    /**
     * Adds resolvers that tell which tenant and assignment scope a resource belongs to, keyed by resource model.
     *
     * @param  array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>>  $resolvers
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function resourceScopes(array $resolvers): static
    {
        $scopes = [];

        foreach (self::input($resolvers) as $resource => $resolver) {
            if (! is_string($resource) || ! is_subclass_of($resource, Model::class)) {
                throw $this->invalid('resourceScopes() expects Eloquent model classes as keys, got '.self::describe($resource));
            }

            $scopes[] = [
                'resource' => ltrim($resource, '\\'),
                'resolver' => $this->resolver('resourceScopes', $resolver, ResourceScopeResolver::class),
            ];
        }

        return $this->record(PanelRecipe::RESOURCE_SCOPES, $scopes);
    }

    /**
     * @throws RegistryFrozenException
     */
    private function record(string $setting, mixed $value): static
    {
        $this->recipe->record($setting, $value);

        return $this;
    }

    /**
     * @return Source|class-string<BackedEnum>|string
     *
     * @throws DefinitionException
     */
    private function definition(mixed $definition): Source|string
    {
        if ($definition instanceof Source) {
            return $definition;
        }

        if (! is_string($definition) || $definition === '') {
            throw $this->invalid(
                'permissions() expects string-backed enum classes, source objects or names of registered sources, got '.self::describe($definition),
            );
        }

        $name = ltrim($definition, '\\');

        if (enum_exists($name)) {
            if ((new ReflectionEnum($name))->getBackingType()?->getName() !== 'string') {
                throw $this->invalid('permissions() expects a string-backed enum, '.$name.' is not one');
            }

            return $name;
        }

        if (str_contains($name, '\\') || class_exists($name) || interface_exists($name) || trait_exists($name)) {
            throw $this->invalid(
                'permissions() got the class name '.$name.', which is not a string-backed enum; a source is attached as an object or by its registered name',
            );
        }

        return $name;
    }

    /**
     * @param  array<mixed>  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    private function hooks(string $setting, string $method, array $hooks): static
    {
        foreach ($hooks as $hook) {
            if (! is_object($hook) && (! is_string($hook) || $hook === '')) {
                throw $this->invalid($method.'() expects class names, objects or closures, got '.self::describe($hook));
            }
        }

        return $this->record($setting, array_values($hooks));
    }

    /**
     * @param  class-string  $contract
     *
     * @throws DefinitionException
     */
    private function resolver(string $method, mixed $resolver, string $contract): object|string
    {
        if ($resolver instanceof $contract) {
            return $resolver;
        }

        if (is_string($resolver) && is_subclass_of($resolver, $contract)) {
            return ltrim($resolver, '\\');
        }

        throw $this->invalid($method.'() expects objects or classes that implement '.$contract.', got '.self::describe($resolver));
    }

    /**
     * Arguments are validated as they arrive: the documented type is a promise of the caller, not a fact.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private static function input(array $input): array
    {
        return $input;
    }

    private function invalid(string $problem): DefinitionException
    {
        return new DefinitionException('Panel "'.$this->recipe->panelId().'": '.$problem.'.');
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? '"'.$value.'"' : get_debug_type($value);
    }
}
