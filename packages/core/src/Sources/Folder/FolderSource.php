<?php

declare(strict_types=1);

namespace AzGuard\Sources\Folder;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Policies\PolicyFor;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\PanelSources;
use BackedEnum;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use UnitEnum;

/**
 * The folder of a panel: permission enums, policies, roles and scopes found next to the provider.
 *
 * Every panel has this source first. One `FolderSource` in `permissions([...])` configures that
 * built-in source; a second one is rejected. Names contributed by a plugin are stored as declared.
 *
 * @api
 */
final class FolderSource implements DescribesSchema, ProvidesGrants, ProvidesPermissions, ProvidesPolicies, ProvidesRoleGrants, ProvidesRoles
{
    /** @var array{permissions: ?string, policies: ?string, roles: ?string, abilities: ?string} */
    private array $folders = [
        'permissions' => null,
        'policies' => null,
        'roles' => null,
        'abilities' => null,
    ];

    private ?DiscoverySnapshot $discovery = null;

    private ?PanelRecipe $recipe = null;

    private ?Container $container = null;

    /** @var list<class-string<BaseRole>>|null */
    private ?array $roleClasses = null;

    public static function make(): static
    {
        return new self;
    }

    /**
     * Replaces the configured names of the permission, policy, role and ability folders.
     *
     * Names left null keep the `discovery` section of the configuration. `permissions` and `policies`
     * must stay different directories.
     */
    public function folders(
        ?string $permissions = null,
        ?string $policies = null,
        ?string $roles = null,
        ?string $abilities = null,
    ): static {
        $copy = clone $this;
        $copy->folders = [
            'permissions' => $permissions,
            'policies' => $policies,
            'roles' => $roles,
            'abilities' => $abilities,
        ];

        return $copy;
    }

    /**
     * @return array{permissions: ?string, policies: ?string, roles: ?string, abilities: ?string}
     */
    public function folderNames(): array
    {
        return $this->folders;
    }

    public function id(): string
    {
        return 'folder';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /**
     * The one folder source written into the recipe, with the layer that wrote it.
     *
     * @return array{source: self, origin: string, plugin: ?string}|null
     *
     * @throws DefinitionException when the recipe names more than one folder source
     */
    public static function configured(PanelRecipe $recipe): ?array
    {
        $found = null;

        foreach ($recipe->layered(PanelRecipe::PERMISSIONS) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $definition) {
                if (! $definition instanceof self) {
                    continue;
                }

                if ($found !== null) {
                    throw new DefinitionException(
                        'Panel "'.$recipe->panelId().'" names more than one folder source: permissions([...]) configures the built-in '
                        .'folder source once.',
                    );
                }

                $origin = $record['origin'];
                $found = [
                    'source' => $definition,
                    'origin' => $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'],
                    'plugin' => $origin['plugin'],
                ];
            }
        }

        return $found;
    }

    public function bind(DiscoverySnapshot $discovery, PanelRecipe $recipe, Container $container): void
    {
        $this->discovery = $discovery;
        $this->recipe = $recipe;
        $this->container = $container;
    }

    /**
     * The engine supplies the compiled panel roles, including roles declared by other sources.
     *
     * @param  list<class-string<BaseRole>>  $classes
     */
    public function bindRoleClasses(array $classes): void
    {
        $this->roleClasses = $classes;
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        $discovery = $this->prepared($panel);
        $definitions = [];
        $known = [];

        foreach ($discovery->definitions as $row) {
            $this->validateAutomaticGrant($row);
            $known[$row['case']['enum']] = true;
            $this->remember($definitions, AttributeReader::definition($row), $panel);
        }

        foreach ($this->recipe()->enums() as $enum) {
            if (isset($known[$enum]) || ! is_subclass_of($enum, BackedEnum::class)) {
                continue;
            }

            foreach (AttributeReader::rows($enum, null) as $row) {
                $this->validateAutomaticGrant($row);
                $this->remember($definitions, AttributeReader::definition($row), $panel);
            }
        }

        return $definitions;
    }

    public function roles(Panel $panel): iterable
    {
        $this->prepared($panel);
        $roles = [];

        foreach ([...$this->discovery()->roles, ...$this->recipe()->roles()] as $class) {
            if (isset($roles[$class])) {
                continue;
            }

            $role = $this->container()->make($class);
            $roles[$class] = $role instanceof BaseRole ? $role : throw new DefinitionException(
                'Panel "'.$panel->id().'" found the role '.$class.', and the container returned '.get_debug_type($role).'.',
            );
        }

        return array_values($roles);
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $model = $context->subjectModel();
        $panel = $context->panel();

        if ($model === null || ! $panel->accepts($subject) || ! $panel->accepts($model)) {
            return;
        }

        $classes = $this->roleClasses ?? [...$this->discovery()->roles, ...$this->recipe()->roles()];

        foreach (array_unique($classes) as $class) {
            if (! is_subclass_of($class, GrantedAutomatically::class)) {
                continue;
            }

            $role = $this->container()->make($class);

            if (! $role instanceof BaseRole) {
                throw new DefinitionException('The automatic role '.$class.' resolved '.get_debug_type($role).'.');
            }

            if (! $role instanceof GrantedAutomatically) {
                throw new DefinitionException('The automatic role '.$class.' does not implement '.GrantedAutomatically::class.'.');
            }

            foreach ($scopes as $scope) {
                if ($role->appliesTo($model, $scope)) {
                    yield RoleContribution::of(RoleKey::of($panel->id(), $role->key()), $scope, 'folder', origin: 'automatic');
                }
            }
        }
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $panel = $context->panel();

        if ($context->subjectModel() === null || ! $panel->accepts($subject)) {
            return;
        }

        $discovery = $this->prepared($panel);
        $permissions = $discovery->grantedToAll;

        foreach ($this->recipe()->enums() as $enum) {
            if (! is_subclass_of($enum, BackedEnum::class)) {
                continue;
            }

            foreach (AttributeReader::rows($enum, null) as $row) {
                $this->validateAutomaticGrant($row);

                if ($row['granted_to_all']) {
                    $permissions[] = $row['local'];
                }
            }
        }

        foreach (array_unique($permissions) as $permission) {
            foreach ($scopes as $scope) {
                yield Grant::of(PermissionPattern::of($panel->id(), $permission), 'folder', $scope, origin: 'granted_to_all');
            }
        }
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }

    /** @param array{local: string, authority: string, granted_to_all: bool} $row */
    private function validateAutomaticGrant(array $row): void
    {
        if ($row['granted_to_all'] && $row['authority'] !== PermissionAuthority::Grants->value) {
            throw new DefinitionException('Permission "'.$row['local'].'" declares #[GrantedToAll] in PolicyOnly mode.');
        }
    }

    public function policies(Panel $panel): iterable
    {
        $discovery = $this->prepared($panel);
        $bindings = [];
        $seen = [];

        foreach ($discovery->bindings as $row) {
            $binding = $this->binding($row, $panel);
            $bindings[] = $binding;
            $seen[$this->local($binding, $panel)] = $binding->policy;
        }

        foreach ($this->recipe()->items(PanelRecipe::POLICIES) as $declared) {
            foreach ($this->declared($declared, $panel) as $binding) {
                $local = $this->local($binding, $panel);
                $owner = $seen[$local] ?? null;

                if ($owner !== null) {
                    throw new DuplicatePolicyBindingException(
                        'Permission "'.$local.'" of panel "'.$panel->id().'" is already bound to '.$owner
                        .' and policies() binds it to '.$binding->policy.': a permission has one policy.',
                    );
                }

                $seen[$local] = $binding->policy;
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        return new SourceDescription($this->id(), self::class, PanelSources::capabilities($this), false);
    }

    /**
     * @param  list<PermissionDefinition>  $definitions
     *
     * @throws DuplicatePermissionException
     */
    private function remember(array &$definitions, PermissionDefinition $definition, Panel $panel): void
    {
        foreach ($definitions as $existing) {
            if ($existing->local !== $definition->local && $existing->case !== $definition->case) {
                continue;
            }

            if ($existing->equals($definition)) {
                return;
            }

            throw new DuplicatePermissionException(
                'Permission "'.$definition->local.'" of panel "'.$panel->id().'" is declared more than once in the panel folders: '
                .'one permission has one definition.',
            );
        }

        $definitions[] = $definition;
    }

    /**
     * @param  array{permission: string|array{enum: string, name: string}, policy: class-string, method: string, roots: list<string>}  $row
     */
    private function binding(array $row, Panel $panel): PolicyBinding
    {
        $permission = $row['permission'];

        if (is_array($permission)) {
            $case = constant($permission['enum'].'::'.$permission['name']);
            $permission = $case instanceof UnitEnum ? $case : throw new DefinitionException(
                'Panel "'.$panel->id().'" cached '.$permission['enum'].'::'.$permission['name'].', which is not an enum case.',
            );
        }

        return PolicyBinding::for($permission, $row['policy'], $row['method']);
    }

    /**
     * @return list<PolicyBinding>
     *
     * @throws DefinitionException
     * @throws InvalidPolicyStructureException
     */
    private function declared(mixed $declared, Panel $panel): array
    {
        if ($declared instanceof PolicyBinding) {
            return [$declared];
        }

        if (! is_string($declared) || ! class_exists($declared)) {
            throw new DefinitionException(
                'Panel "'.$panel->id().'": policies() expects policy classes or '.PolicyBinding::class.' objects, got '.get_debug_type($declared).'.',
            );
        }

        $enum = $this->pairedEnum($declared, $panel);
        $bindings = [];

        foreach ((new ReflectionClass($declared))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $declared || str_starts_with($method->getName(), '__')) {
                continue;
            }

            foreach ($method->getAttributes(Decides::class) as $attribute) {
                $permission = $attribute->newInstance()->permission;
                $this->accepts($permission, $enum, $declared, $method->getName(), $panel);
                $bindings[] = PolicyBinding::for($permission, $declared, $method->getName());
            }
        }

        return $bindings;
    }

    /**
     * @param  class-string  $class
     *
     * @throws DefinitionException
     * @throws InvalidPolicyStructureException
     */
    private function pairedEnum(string $class, Panel $panel): ?string
    {
        $attributes = (new ReflectionClass($class))->getAttributes(PolicyFor::class);

        if ($attributes === []) {
            return null;
        }

        $enum = $attributes[0]->newInstance()->permissions;

        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new DefinitionException(
                'Panel "'.$panel->id().'": '.$class.' declares #[PolicyFor] for '.json_encode($enum).', which is not a string-backed enum.',
            );
        }

        return $enum;
    }

    /**
     * @throws InvalidPolicyStructureException
     */
    private function accepts(UnitEnum|string $permission, ?string $enum, string $class, string $method, Panel $panel): void
    {
        if ($permission instanceof UnitEnum) {
            if ($enum !== null && $permission::class !== $enum) {
                throw new InvalidPolicyStructureException(
                    'Panel "'.$panel->id().'": '.$class.'::'.$method.' decides '.$permission::class.'::'.$permission->name
                    .', which is outside '.$enum.'.',
                );
            }

            return;
        }

        if ($enum === null) {
            return;
        }

        if (! is_subclass_of($enum, BackedEnum::class)) {
            return;
        }

        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            if ($case->getBackingValue() === $permission) {
                return;
            }
        }

        throw new InvalidPolicyStructureException(
            'Panel "'.$panel->id().'": '.$class.'::'.$method.' decides '.json_encode($permission).', which is not a case of '.$enum.'.',
        );
    }

    private function local(PolicyBinding $binding, Panel $panel): string
    {
        $permission = $binding->permission;

        if ($permission instanceof BackedEnum && is_string($permission->value)) {
            return $permission->value;
        }

        if (is_string($permission)) {
            return $permission;
        }

        throw new DefinitionException('Panel "'.$panel->id().'" has a policy binding whose permission is not a local name.');
    }

    private function prepared(Panel $panel): DiscoverySnapshot
    {
        return $this->discovery ?? throw new DefinitionException(
            'The folder source of panel "'.$panel->id().'" was read before the panel was compiled.',
        );
    }

    private function discovery(): DiscoverySnapshot
    {
        return $this->discovery ?? throw new DefinitionException('The folder source has no discovery result.');
    }

    private function recipe(): PanelRecipe
    {
        return $this->recipe ?? throw new DefinitionException('The folder source has no panel recipe.');
    }

    private function container(): Container
    {
        return $this->container ?? throw new DefinitionException('The folder source has no container.');
    }
}
