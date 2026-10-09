<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

use AzGuard\Contracts\Catalog\PermissionCatalog;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\Panel;
use AzGuard\Policies\Decides;
use AzGuard\Policies\NativeGateBinding;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\PanelSources;
use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use UnitEnum;

/**
 * The catalog of a panel: permissions, code roles and policy bindings contributed by the static sources of the panel.
 *
 * A key is the panel and the local name as the source declared it; neither a plugin nor the panel changes the name.
 * A name contributed twice with equal definitions is one permission owned by the first source; any difference is a
 * collision. Lookups read hash indexes: local name → definition, enum case → local name, first segment → a name.
 *
 * @phpstan-import-type CompiledRole from RoleCompiler
 *
 * @phpstan-type Owner array{source: string, origin: string}
 * @phpstan-type SourceEntry array{id: string, class: string, origin: string}
 * @phpstan-type DefinitionEntry array{local: string, authority: string, label: ?string, group: ?string, description: ?string, case: array{enum: string, name: string}|null, resource_model: class-string<Model>|null, source: string, origin: string}
 * @phpstan-type BindingEntry array{permission: string|array{enum: string, name: string}, kind: string, policy: class-string|null, method: ?string, ability: ?string, resource_model: class-string<Model>|null}
 * @phpstan-type Snapshot array{panel: string, prefix: ?string, dynamic: bool, sources: list<SourceEntry>, permissions: list<DefinitionEntry>, bindings: array<string, class-string>, binding_methods: array<string, string>, policy_bindings: array<string, BindingEntry>, ability_index: array<class-string<Model>, array<string, ?string>>, roles: array<string, CompiledRole>}
 */
final class PanelCatalog implements PermissionCatalog
{
    /** @var array<string, PermissionDefinition> static and dynamic definitions by local name */
    private array $definitions = [];

    /** @var array<string, Owner> */
    private array $owners = [];

    /** @var array<string, string> `Enum::Case` => local name */
    private array $enumKeys = [];

    /** @var array<string, string> first segment of a static name => the first static name with it */
    private array $segments = [];

    /** @var array<string, PermissionDefinition> */
    private array $static = [];

    /** @var array<string, class-string> local name => policy class */
    private array $bindings = [];

    /** @var array<string, string> local name => policy method that carries #[Decides] */
    private array $bindingMethods = [];

    /** @var array<string, PolicyBinding> */
    private array $policyBindings = [];

    /** @var array<class-string<Model>, array<string, ?string>> */
    private array $abilityIndex = [];

    /** @var array<string, CompiledRole> */
    private array $roles = [];

    /**
     * @param  list<SourceEntry>  $sources
     */
    private function __construct(
        private readonly string $panel,
        private readonly ?string $prefix,
        private readonly bool $dynamic,
        private readonly array $sources,
    ) {}

    /**
     * Builds the static catalog of a panel; dynamic sources are not read while the application boots.
     *
     * @throws DuplicatePermissionException
     * @throws DuplicateRoleException
     * @throws DuplicatePolicyBindingException
     * @throws InvalidPolicyStructureException
     * @throws InvalidSourceContributionException when a source returns something that is not a definition
     * @throws UnknownPermissionException
     * @throws DefinitionException
     */
    public static function build(Panel $panel, PanelSources $sources, Container $container): self
    {
        $permissionSources = $sources->with(ProvidesPermissions::class);
        $catalog = new self(
            $panel->id(),
            $panel->prefix(),
            array_filter($permissionSources, static fn (array $attached): bool => $attached['source']->isDynamic()) !== [],
            array_map(static fn (array $attached): array => [
                'id' => $attached['source']->id(),
                'class' => $attached['source']::class,
                'origin' => $attached['origin'],
            ], $sources->all()),
        );

        foreach ($permissionSources as ['source' => $source, 'origin' => $origin]) {
            if ($source->isDynamic()) {
                continue;
            }

            foreach (self::untrusted($source->permissions($panel)) as $definition) {
                if (! $definition instanceof PermissionDefinition) {
                    throw new InvalidSourceContributionException(
                        'Source "'.$source->id().'" of panel "'.$panel->id().'" returned '.get_debug_type($definition)
                        .' from permissions(); a source returns '.PermissionDefinition::class.' objects.',
                    );
                }

                $catalog->add($definition, ['source' => $source->id(), 'origin' => $origin]);
            }
        }

        $catalog->static = $catalog->definitions;
        $catalog->buildAbilityIndex();
        $catalog->bind($panel, $sources, $container);
        $catalog->roles = (new RoleCompiler($container))->compile($panel, $catalog, $sources);

        return $catalog;
    }

    /**
     * Restores a catalog from its snapshot; null when the snapshot does not describe a valid catalog.
     *
     * @param  array<mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): ?self
    {
        try {
            /** @var Snapshot $snapshot */
            $catalog = new self($snapshot['panel'], $snapshot['prefix'], $snapshot['dynamic'], $snapshot['sources']);

            foreach ($snapshot['permissions'] as $entry) {
                $case = $entry['case'] === null ? null : constant($entry['case']['enum'].'::'.$entry['case']['name']);

                $catalog->add(new PermissionDefinition(
                    local: $entry['local'],
                    authority: PermissionAuthority::from($entry['authority']),
                    label: $entry['label'],
                    group: $entry['group'],
                    description: $entry['description'],
                    case: $case instanceof BackedEnum ? $case : null,
                    resourceModel: $entry['resource_model'],
                ), ['source' => $entry['source'], 'origin' => $entry['origin']]);
            }

            $catalog->static = $catalog->definitions;
            foreach ($snapshot['policy_bindings'] as $local => $row) {
                $permission = is_array($row['permission']) ? constant($row['permission']['enum'].'::'.$row['permission']['name']) : $row['permission'];

                if ($row['kind'] === 'php' && $row['policy'] !== null) {
                    $binding = PolicyBinding::for($permission, $row['policy'], $row['method']);
                } elseif ($row['kind'] === 'gate' && $row['ability'] !== null) {
                    $binding = PolicyBinding::gate($permission, $row['ability'], $row['resource_model']);
                } else {
                    return null;
                }
                $catalog->rememberBinding($local, $binding);
            }
            $catalog->buildAbilityIndex();
            $catalog->roles = $snapshot['roles'];
        } catch (Throwable) {
            // The file is data written by an earlier build: whatever does not restore is rebuilt from the sources.
            return null;
        }

        return $catalog->snapshot() === $snapshot ? $catalog : null;
    }

    public function panel(): string
    {
        return $this->panel;
    }

    public function has(PermissionKey|string $permission): bool
    {
        return $this->local($permission) !== null;
    }

    public function find(PermissionKey|string $permission): ?PermissionDefinition
    {
        $local = $this->local($permission);

        return $local === null ? null : $this->definitions[$local];
    }

    public function get(PermissionKey|string $permission): PermissionDefinition
    {
        return $this->find($permission) ?? throw new UnknownPermissionException(
            'Permission "'.($permission instanceof PermissionKey ? $permission->full() : $permission).'" is not in the catalog of panel "'
            .$this->panel.'".',
        );
    }

    public function keyOf(UnitEnum $case): PermissionKey
    {
        $local = $this->enumKeys[$case::class.'::'.$case->name] ?? throw new UnknownPermissionException(
            'Enum case '.$case::class.'::'.$case->name.' names no permission of panel "'.$this->panel.'".',
        );

        return PermissionKey::of($this->panel, $local);
    }

    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Whether a static name of the catalog starts with the segment; the name when it does.
     */
    public function nameWithFirstSegment(string $segment): ?string
    {
        return $this->segments[$segment] ?? null;
    }

    /**
     * Whether a source of the panel contributes permissions while the application runs.
     */
    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /**
     * @return array<string, class-string> policy class by local name
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * The attributed policy method bound to a local permission name.
     */
    public function bindingMethod(string $permission): ?string
    {
        if (($this->policyBindings[$permission]->kind ?? null) === 'gate') {
            throw new DefinitionException('Native gate bindings have no attributed PHP method.');
        }

        return $this->bindingMethods[$permission] ?? null;
    }

    /** @return array<string, PolicyBinding> */
    public function policyBindings(): array
    {
        return $this->policyBindings;
    }

    public function permissionForAbility(string $model, string $ability): ?string
    {
        return $this->abilityIndex[$model][Str::snake($ability)] ?? null;
    }

    public function abilityIsAmbiguous(string $model, string $ability): bool
    {
        $word = Str::snake($ability);

        return array_key_exists($word, $this->abilityIndex[$model] ?? []) && $this->abilityIndex[$model][$word] === null;
    }

    private function buildAbilityIndex(): void
    {
        $this->abilityIndex = [];
        foreach ($this->static as $local => $definition) {
            if ($definition->resourceModel === null) {
                continue;
            }
            $parts = explode('.', $local);
            $word = Str::snake(end($parts));
            $model = $definition->resourceModel;
            $this->abilityIndex[$model][$word] = array_key_exists($word, $this->abilityIndex[$model] ?? []) ? null : $local;
        }
    }

    /**
     * @return array<string, CompiledRole> compiled code roles by role key
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * The catalog with the dynamic permissions of one tenant over its static part; the catalog itself is unchanged.
     *
     * @param  iterable<PermissionDefinition>  $definitions
     *
     * @throws InvalidSourceContributionException when a dynamic permission is not decided by grants
     * @throws DuplicatePermissionException when a dynamic name repeats a static one
     * @throws PrefixConflictException when a dynamic name starts with the panel prefix
     */
    public function withDynamic(iterable $definitions): self
    {
        $catalog = clone $this;
        $catalog->definitions = $this->static;
        $catalog->owners = array_intersect_key($this->owners, $this->static);
        $segments = [];
        foreach (array_keys($this->static) as $local) {
            $segments[explode('.', $local, 2)[0]] ??= $local;
        }
        $catalog->enumKeys = array_filter(
            $this->enumKeys,
            fn (string $local): bool => isset($this->static[$local]),
        );

        foreach (self::untrusted($definitions) as $definition) {
            if (! $definition instanceof PermissionDefinition || $definition->authority !== PermissionAuthority::Grants) {
                throw new InvalidSourceContributionException(
                    'Panel "'.$this->panel.'": a dynamic permission is always decided by grants, got '
                    .($definition instanceof PermissionDefinition ? 'the authority "'.$definition->authority->value.'" for "'.$definition->local.'"' : get_debug_type($definition))
                    .'.',
                );
            }

            if (isset($this->static[$definition->local])) {
                throw new DuplicatePermissionException(
                    'Dynamic permission "'.$definition->local.'" of panel "'.$this->panel.'" repeats a static permission of source "'
                    .$this->owners[$definition->local]['source'].'": static names are reserved in every tenant.',
                );
            }

            if ($this->prefix !== null && explode('.', $definition->local, 2)[0] === $this->prefix) {
                throw new PrefixConflictException(
                    'Dynamic permission "'.$definition->local.'" of panel "'.$this->panel.'" starts with the panel prefix "'
                    .$this->prefix.'": the name would read as a prefixed one.',
                );
            }

            $catalog->add($definition, ['source' => 'dynamic', 'origin' => 'dynamic']);
        }

        $catalog->segments = $segments;

        return $catalog;
    }

    /**
     * Scalars only: no object, closure or service, so the snapshot can be written as a PHP array.
     *
     * @return Snapshot
     */
    public function snapshot(): array
    {
        $permissions = [];

        foreach ($this->static as $local => $definition) {
            $permissions[] = [
                'local' => $local,
                'authority' => $definition->authority->value,
                'label' => $definition->label,
                'group' => $definition->group,
                'description' => $definition->description,
                'case' => $definition->case === null ? null : ['enum' => $definition->case::class, 'name' => $definition->case->name],
                'resource_model' => $definition->resourceModel,
                'source' => $this->owners[$local]['source'],
                'origin' => $this->owners[$local]['origin'],
            ];
        }

        $bindings = [];
        foreach ($this->policyBindings as $local => $binding) {
            $bindings[$local] = [
                'permission' => $binding->permission instanceof BackedEnum ? ['enum' => $binding->permission::class, 'name' => $binding->permission->name] : $binding->permission,
                'kind' => $binding->kind,
                'policy' => $binding->policy,
                'method' => $binding->method,
                'ability' => $binding->ability,
                'resource_model' => $binding->resourceModel,
            ];
        }

        return [
            'panel' => $this->panel,
            'prefix' => $this->prefix,
            'dynamic' => $this->dynamic,
            'sources' => $this->sources,
            'permissions' => $permissions,
            'bindings' => $this->bindings,
            'binding_methods' => $this->bindingMethods,
            'policy_bindings' => $bindings,
            'ability_index' => $this->abilityIndex,
            'roles' => $this->roles,
        ];
    }

    /**
     * @param  Owner  $owner
     *
     * @throws DuplicatePermissionException
     */
    private function add(PermissionDefinition $definition, array $owner): void
    {
        $local = $definition->local;
        $existing = $this->definitions[$local] ?? null;

        if ($existing !== null && $this->owners[$local]['origin'] !== $owner['origin']) {
            throw new DuplicatePermissionException(
                'Permission "'.$local.'" of panel "'.$this->panel.'" is contributed by '.self::owner($this->owners[$local]).' and '
                .self::owner($owner).': independent origins cannot share a permission name; rename one of them.',
            );
        }

        if ($existing !== null && ! $existing->equals($definition)) {
            throw new DuplicatePermissionException(
                'Permission "'.$local.'" of panel "'.$this->panel.'" is defined differently by '.self::owner($this->owners[$local]).' and '
                .self::owner($owner).': one permission has one definition; rename one of them.',
            );
        }

        if ($definition->case !== null) {
            $enumKey = $definition->case::class.'::'.$definition->case->name;
            $named = $this->enumKeys[$enumKey] ?? null;

            if ($named !== null && $named !== $local) {
                throw new DuplicatePermissionException(
                    'Enum case '.$enumKey.' of panel "'.$this->panel.'" names "'.$named.'" in '.self::owner($this->owners[$named])
                    .' and "'.$local.'" in '.self::owner($owner).': a case names one permission of a panel.',
                );
            }

            $this->enumKeys[$enumKey] = $local;
        }

        if ($existing !== null) {
            return;
        }

        $this->definitions[$local] = $definition;
        $this->owners[$local] = $owner;
        $this->segments[explode('.', $local, 2)[0]] ??= $local;
    }

    /**
     * @throws UnknownPermissionException
     * @throws DuplicatePolicyBindingException
     * @throws InvalidPolicyStructureException
     * @throws InvalidSourceContributionException
     * @throws DefinitionException
     */
    private function bind(Panel $panel, PanelSources $sources, Container $container): void
    {
        $boundBy = [];

        foreach ($sources->with(ProvidesPolicies::class) as ['source' => $source, 'origin' => $origin]) {
            $owner = ['source' => $source->id(), 'origin' => $origin];

            foreach (self::untrusted($source->policies($panel)) as $binding) {
                if (! $binding instanceof PolicyBinding) {
                    throw new InvalidSourceContributionException(
                        'Source "'.$source->id().'" of panel "'.$this->panel.'" returned '.get_debug_type($binding)
                        .' from policies(); a source returns '.PolicyBinding::class.' objects.',
                    );
                }

                try {
                    $local = $binding->permission instanceof BackedEnum
                        ? $this->keyOf($binding->permission)->local()
                        : $this->get($binding->permission)->local;
                } catch (UnknownPermissionException $error) {
                    if ($binding->kind === 'gate') {
                        throw new DefinitionException('Native gate mapping requires a declared permission.', previous: $error);
                    }

                    throw $error;
                }

                $bound = $this->policyBindings[$local] ?? null;

                if ($bound !== null && ($bound->kind !== $binding->kind || $bound->policy !== $binding->policy
                    || $bound->ability !== $binding->ability || $bound->resourceModel !== $binding->resourceModel)) {
                    throw new DuplicatePolicyBindingException(
                        'Permission "'.$local.'" of panel "'.$this->panel.'" is bound to '.($bound->policy ?? 'gate:'.$bound->ability).' by '.self::owner($boundBy[$local])
                        .' and to '.($binding->policy ?? 'gate:'.$binding->ability).' by '.self::owner($owner).': bind a permission to one policy.',
                    );
                }

                if ($binding->kind === 'gate') {
                    $this->assertExternalAbility($binding);

                    try {
                        $container->make(NativeGateBinding::class)->resolve($binding);
                    } catch (Throwable $error) {
                        throw new DefinitionException('Panel "'.$this->panel.'" has an invalid native gate binding: '.$error->getMessage(), previous: $error);
                    }
                } else {
                    $method = $this->bindingMethodFor($binding);
                    $binding = PolicyBinding::for($binding->permission, $binding->policy ?? throw new DefinitionException('PHP binding has no policy.'), $method);
                }

                if ($bound === null) {
                    $this->rememberBinding($local, $binding);
                    $boundBy[$local] = $owner;
                }
            }
        }

        foreach ($this->static as $local => $definition) {
            if ($definition->authority === PermissionAuthority::Policy && ! isset($this->policyBindings[$local])) {
                throw new InvalidPolicyStructureException(
                    'Permission "'.$local.'" of panel "'.$this->panel.'" is decided by its policy alone, but no policy is bound to it: '
                    .'add PolicyBinding::for(…) for it.',
                );
            }
        }
    }

    private function rememberBinding(string $local, PolicyBinding $binding): void
    {
        $this->policyBindings[$local] = $binding;

        if ($binding->kind === 'php' && $binding->policy !== null && $binding->method !== null) {
            $this->bindings[$local] = $binding->policy;
            $this->bindingMethods[$local] = $binding->method;
        }
    }

    public function assertExternalAbility(PolicyBinding $binding): void
    {
        foreach ($this->static as $local => $definition) {
            if ($binding->ability === $local || $binding->ability === PermissionKey::of($this->panel, $local)->full()
                || ($this->prefix !== null && $binding->ability === $this->prefix.'.'.$local)) {
                throw new DefinitionException('Native gate mapping cannot target an AzGuard-owned ability.');
            }
        }
    }

    /**
     * Every source declares the same contract: exactly one public nonstatic method decides the action.
     *
     * @throws DefinitionException
     */
    private function bindingMethodFor(PolicyBinding $binding): string
    {
        $methods = [];
        $policy = $binding->policy ?? throw new DefinitionException('PHP binding has no policy class.');

        foreach ((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            foreach ($method->getAttributes(Decides::class) as $attribute) {
                if (self::samePermission($attribute->newInstance()->permission, $binding->permission)) {
                    $methods[$method->getName()] = true;
                }
            }
        }

        if (count($methods) !== 1) {
            throw new DefinitionException(
                'Panel "'.$this->panel.'" binds '.self::permissionName($binding->permission).' to '.$binding->policy
                .', which has '.count($methods).' public nonstatic methods with #[Decides] for that permission: the policy needs exactly one.',
            );
        }

        $method = array_key_first($methods);

        if ($binding->method !== null && $binding->method !== $method) {
            throw new DefinitionException(
                'Panel "'.$this->panel.'" binds '.self::permissionName($binding->permission).' to '.$binding->policy.'::'.$binding->method
                .', but #[Decides] selects '.$method.': the explicit method must match the attributed method.',
            );
        }

        return $method;
    }

    private static function samePermission(UnitEnum|string $declared, BackedEnum|string $wanted): bool
    {
        if ($declared instanceof UnitEnum && $wanted instanceof UnitEnum) {
            return $declared === $wanted;
        }

        $left = $declared instanceof BackedEnum && is_string($declared->value) ? $declared->value : $declared;
        $right = $wanted instanceof BackedEnum && is_string($wanted->value) ? $wanted->value : $wanted;

        return is_string($left) && $left === $right;
    }

    private static function permissionName(BackedEnum|string $permission): string
    {
        return $permission instanceof UnitEnum ? $permission::class.'::'.$permission->name : '"'.$permission.'"';
    }

    /**
     * What an extension returns is checked as it arrives: the documented type is a promise of the extension, not a fact.
     *
     * @param  iterable<mixed>  $values
     * @return iterable<mixed>
     */
    public static function untrusted(iterable $values): iterable
    {
        return $values;
    }

    /**
     * The local name of a permission of the catalog, or null.
     */
    private function local(PermissionKey|string $permission): ?string
    {
        if ($permission instanceof PermissionKey) {
            if ($permission->panel() !== $this->panel) {
                return null;
            }

            $permission = $permission->local();
        }

        return isset($this->definitions[$permission]) ? $permission : null;
    }

    /**
     * @param  Owner  $owner
     */
    private static function owner(array $owner): string
    {
        return 'source "'.$owner['source'].'"'.($owner['origin'] === 'provider' ? '' : ' ('.$owner['origin'].')');
    }
}
