<?php

/**
 * Committed snapshot of the public @api surface of packages/core (P3.2, D20).
 *
 * DO NOT edit by hand and DO NOT regenerate just to silence a red
 * ApiBoundaryTest: after the P3 freeze, every surface change goes through an
 * explicit D# decision entry with bump semantics (root/semver-policy.md, P3.3).
 * Deliberate regeneration: composer test:api-snapshot:update
 */

return [
    'AzGuard\\Contracts\\AbilitiesResolver' => [
        'kind' => 'interface',
        'methods' => [
            'forUser(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId, array $keys): array',
        ],
    ],
    'AzGuard\\Contracts\\AzGuardManagerInterface' => [
        'kind' => 'interface',
        'methods' => [
            'abilitiesFor(Illuminate\\Contracts\\Auth\\Authenticatable $user, BackedEnum|string|null $panelId, array $keys): array',
            'currentPanel(): ?AzGuard\\Panels\\Panel',
            'forUser(Illuminate\\Contracts\\Auth\\Authenticatable $user): AzGuard\\Grants\\GrantBuilder',
            'getPanels(): array',
            'hasContextGuard(): bool',
            'panel(BackedEnum|string $id): ?AzGuard\\Panels\\Panel',
            'permission(BackedEnum|string $panelId, UnitEnum|string $permission): string',
            'registerCatalogBuilder(string $builderClass): void',
            'registerGrantSource(string $sourceClass): void',
            'registerPanel(AzGuard\\Panels\\Panel|callable $panel): void',
            'setCurrentPanel(?AzGuard\\Panels\\Panel $panel): void',
        ],
    ],
    'AzGuard\\Contracts\\AzGuardUser' => [
        'kind' => 'interface',
        'methods' => [],
    ],
    'AzGuard\\Contracts\\ContextGrantBuilder' => [
        'kind' => 'interface',
        'methods' => [
            'grant(UnitEnum|string $permission): Illuminate\\Database\\Eloquent\\Model',
            'grants(): Illuminate\\Database\\Eloquent\\Collection',
            'inContext(string $contextType, string|int $contextId): static',
            'on(BackedEnum|string $panelId): static',
            'revoke(UnitEnum|string $permission): int',
            'revokeAll(): int',
            'ttl(?int $seconds): static',
            'until(?DateTimeInterface $at): static',
        ],
    ],
    'AzGuard\\Contracts\\ContextGrantBuilderFactory' => [
        'kind' => 'interface',
        'methods' => [
            'forUser(Illuminate\\Contracts\\Auth\\Authenticatable $user): AzGuard\\Contracts\\ContextGrantBuilder',
        ],
    ],
    'AzGuard\\Contracts\\ContextGuard' => [
        'kind' => 'interface',
        'methods' => [
            'checkInContext(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $contextType, string|int $contextId, string $permission, string $panelId): bool',
        ],
    ],
    'AzGuard\\Contracts\\HasDirectGrants' => [
        'kind' => 'interface',
        'methods' => [
            'directGrants(): Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
            'grant(UnitEnum|string $permission, BackedEnum|string $panelId, ?DateTimeInterface $expiresAt = default): static',
            'grants(BackedEnum|string $panelId): Illuminate\\Database\\Eloquent\\Collection',
            'hasGrant(UnitEnum|string $permission, BackedEnum|string|null $panelId = default): bool',
            'revoke(UnitEnum|string $permission, BackedEnum|string $panelId): static',
        ],
    ],
    'AzGuard\\Contracts\\HasPermissions' => [
        'kind' => 'interface',
        'methods' => [
            'checkPermission(UnitEnum|string $permission, BackedEnum|string|null $panelId = default, ?AzGuard\\Contracts\\PermissionContext $context = default): bool',
            'flushPermissions(BackedEnum|string|null $panelId = default): void',
            'hasContextGuard(): bool',
            'hasPermission(UnitEnum|string $permission, BackedEnum|string|null $panelId = default, ?AzGuard\\Contracts\\PermissionContext $context = default): bool',
            'hasPermissionIn(string $contextType, string|int $contextId, UnitEnum|string $permission, BackedEnum|string|null $panelId = default): bool',
            'isSuperAdmin(BackedEnum|string|null $panelId = default): bool',
            'permissionSet(BackedEnum|string|null $panelId = default): AzGuard\\Registry\\Values\\PermissionSet',
            'permissions(BackedEnum|string|null $panelId = default): Illuminate\\Support\\Collection',
        ],
    ],
    'AzGuard\\Contracts\\HasRoles' => [
        'kind' => 'interface',
        'methods' => [
            'assignRole(BackedEnum|AzGuard\\Models\\Role|string ...$roles): static',
            'getRoleNames(): Illuminate\\Support\\Collection',
            'hasRole(BackedEnum|AzGuard\\Contracts\\RoleInterface|string $role): bool',
            'removeRole(BackedEnum|AzGuard\\Models\\Role|string ...$roles): static',
            'roles(): Illuminate\\Database\\Eloquent\\Relations\\MorphToMany',
            'scopes(): Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
            'syncRoles(array $roles): static',
        ],
    ],
    'AzGuard\\Contracts\\HasScopedRoles' => [
        'kind' => 'interface',
        'methods' => [
            'assignScopedRole(BackedEnum|AzGuard\\Models\\Role|string $role, Illuminate\\Database\\Eloquent\\Model $entity, BackedEnum|string|null $panelId = default): static',
            'hasScopedPermission(UnitEnum|string $permission, Illuminate\\Database\\Eloquent\\Model $entity, BackedEnum|string|null $panelId = default): bool',
            'hasScopedRole(BackedEnum|AzGuard\\Models\\Role|string $role, Illuminate\\Database\\Eloquent\\Model $entity, BackedEnum|string|null $panelId = default): bool',
            'removeScopedRole(BackedEnum|AzGuard\\Models\\Role|string $role, Illuminate\\Database\\Eloquent\\Model $entity, BackedEnum|string|null $panelId = default): static',
        ],
    ],
    'AzGuard\\Contracts\\Permission' => [
        'kind' => 'interface',
        'methods' => [
            'static ability(): string',
        ],
    ],
    'AzGuard\\Contracts\\PermissionContext' => [
        'kind' => 'interface',
        'methods' => [
            'contextId(): string|int',
            'contextType(): string',
        ],
    ],
    'AzGuard\\Contracts\\PermissionLayer' => [
        'kind' => 'interface',
        'methods' => [
            'apply(AzGuard\\Registry\\Values\\PermissionSet $global, Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): AzGuard\\Registry\\Values\\PermissionSet',
            'cacheDiscriminator(string $panelId): string',
        ],
    ],
    'AzGuard\\Contracts\\PermissionMatcher' => [
        'kind' => 'interface',
        'methods' => [
            'matches(string $pattern, string $key): bool',
        ],
    ],
    'AzGuard\\Contracts\\PermissionResolverInterface' => [
        'kind' => 'interface',
        'methods' => [
            'forUser(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): AzGuard\\Registry\\Values\\PermissionSet',
            'forgetForUser(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): void',
            'forgetRequestCache(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): void',
        ],
    ],
    'AzGuard\\Contracts\\RoleInterface' => [
        'kind' => 'interface',
        'methods' => [
            'getLevel(): int',
            'getName(): string',
            'permissions(): array',
        ],
    ],
    'AzGuard\\Contracts\\RolePermissionValidator' => [
        'kind' => 'interface',
        'methods' => [
            'validate(string $permissionKey, string $panelId): void',
        ],
    ],
    'AzGuard\\Contracts\\ScopeInterface' => [
        'kind' => 'interface',
        'methods' => [
            'apply(Illuminate\\Database\\Eloquent\\Builder $builder, Illuminate\\Database\\Eloquent\\Model $user, ?Illuminate\\Database\\Eloquent\\Model $entity): void',
        ],
    ],
    'AzGuard\\Facades\\AzGuard' => [
        'kind' => 'class',
        'docblockMethods' => [
            'static GrantBuilder forUser(Authenticatable $user)',
            'static Panel|null currentPanel()',
            'static Panel|null panel(string|BackedEnum $id)',
            'static array<string, Panel> getPanels()',
            'static array<string, bool> abilitiesFor(Authenticatable $user, (string | BackedEnum | null) $panelId, array<int, string> $keys)',
            'static bool hasContextGuard()',
            'static string permission(string|BackedEnum $panelId, (string | UnitEnum) $permission)',
            'static void assertChecked((string | UnitEnum | Closure) $key)',
            'static void assertDenied((Authenticatable | Closure) $user, (string | UnitEnum | null) $key = null, (string | BackedEnum | null) $panelId = null)',
            'static void assertGranted((Authenticatable | Closure) $user, (string | UnitEnum | null) $key = null, (string | BackedEnum | null) $panelId = null)',
            'static void registerCatalogBuilder(class-string<PermissionCatalogBuilder> $builderClass)',
            'static void registerGrantSource(class-string<GrantSource> $sourceClass)',
            'static void registerPanel(Panel|callable $panel)',
            'static void setCurrentPanel(?Panel $panel)',
        ],
        'methods' => [
            'static fake(): AzGuard\\Testing\\AzGuardFake',
        ],
    ],
    'AzGuard\\Panels\\Panel' => [
        'kind' => 'class',
        'methods' => [
            'basePath(string $basePath): static',
            'getBasePath(): string',
            'getId(): string',
            'getLabel(): string',
            'getNamespace(): string',
            'getPermissionEnums(): array',
            'getRoleClasses(): array',
            'id(string $id): static',
            'label(string $label): static',
            'namespace(string $namespace): static',
            'path(string $path): static',
            'permissionEnums(array $enums): static',
            'resolvePermission(UnitEnum|string $permission): string',
            'roleClasses(array $classes): static',
            'scopedByPanelId(bool $condition = default): static',
            'static make(): static',
        ],
    ],
    'AzGuard\\Permissions\\PermissionKey' => [
        'kind' => 'class',
        'methods' => [
            'static normalize(UnitEnum|string $permission): string',
        ],
    ],
    'AzGuard\\Registry\\Contracts\\GrantPriority' => [
        'kind' => 'enum',
        'methods' => [],
    ],
    'AzGuard\\Registry\\Contracts\\GrantSource' => [
        'kind' => 'interface',
        'methods' => [
            'permissionsFor(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): AzGuard\\Registry\\Values\\PermissionSet',
            'priority(): int',
        ],
    ],
    'AzGuard\\Registry\\Contracts\\PermissionCatalog' => [
        'kind' => 'interface',
        'methods' => [
            'all(string $panelId): array',
            'assert(string $panelId, string $resolvedKey): AzGuard\\Registry\\Contracts\\PermissionDefinition',
            'flush(): void',
            'get(string $panelId, string $resolvedKey): ?AzGuard\\Registry\\Contracts\\PermissionDefinition',
            'groups(string $panelId): array',
            'has(string $panelId, string $resolvedKey): bool',
            'panels(): array',
        ],
    ],
    'AzGuard\\Registry\\Contracts\\PermissionCatalogBuilder' => [
        'kind' => 'interface',
        'methods' => [
            'build(string $panelId): array',
            'supports(string $panelId): bool',
        ],
    ],
    'AzGuard\\Registry\\Contracts\\PermissionDefinition' => [
        'kind' => 'interface',
        'methods' => [
            'group(): ?string',
            'isDynamic(): bool',
            'key(): string',
            'label(): ?string',
            'meta(): AzGuard\\Registry\\Contracts\\PermissionMeta',
            'panelId(): string',
            'shortKey(): string',
        ],
    ],
    'AzGuard\\Registry\\Contracts\\PermissionMeta' => [
        'kind' => 'interface',
        'methods' => [
            'description(): ?string',
            'label(): ?string',
            'toArray(): array',
        ],
    ],
    'AzGuard\\Registry\\Values\\PermissionSet' => [
        'kind' => 'class',
        'methods' => [
            'count(): int',
            'filter(Closure $callback): self',
            'grants(string $key): bool',
            'has(string $key): bool',
            'isEmpty(): bool',
            'isWildcard(): bool',
            'keys(): array',
            'matchesWildcard(string $key): bool',
            'merge(self $other): self',
            'static empty(): self',
            'static fromKeys(array $keys): self',
            'static fromRawKeys(array $keys): self',
            'static wildcard(): self',
        ],
    ],
    'AzGuard\\Testing\\AzGuardFake' => [
        'kind' => 'class',
        'methods' => [
            '__construct(AzGuard\\Contracts\\AzGuardManagerInterface $manager)',
            'abilitiesFor(Illuminate\\Contracts\\Auth\\Authenticatable $user, BackedEnum|string|null $panelId, array $keys): array',
            'assertChecked(UnitEnum|Closure|string $key): void',
            'assertDenied(Illuminate\\Contracts\\Auth\\Authenticatable|Closure $user, UnitEnum|string|null $key = default, BackedEnum|string|null $panelId = default): void',
            'assertGranted(Illuminate\\Contracts\\Auth\\Authenticatable|Closure $user, UnitEnum|string|null $key = default, BackedEnum|string|null $panelId = default): void',
            'currentPanel(): ?AzGuard\\Panels\\Panel',
            'forUser(Illuminate\\Contracts\\Auth\\Authenticatable $user): AzGuard\\Grants\\GrantBuilder',
            'getPanels(): array',
            'grant(Illuminate\\Contracts\\Auth\\Authenticatable $user, UnitEnum|string $permissionKey, BackedEnum|string|null $panelId = default, ?int $ttl = default): AzGuard\\Models\\DirectGrant',
            'grants(Illuminate\\Contracts\\Auth\\Authenticatable $user, BackedEnum|string|null $panelId = default): Illuminate\\Database\\Eloquent\\Collection',
            'hasContextGuard(): bool',
            'isSuperAdmin(Illuminate\\Contracts\\Auth\\Authenticatable $user, BackedEnum|string|null $panelId = default): bool',
            'panel(BackedEnum|string $id): ?AzGuard\\Panels\\Panel',
            'panelIdForPermission(UnitEnum $permission): ?string',
            'permission(BackedEnum|string $panelId, UnitEnum|string $permission): string',
            'registerCatalogBuilder(string $builderClass): void',
            'registerGrantSource(string $sourceClass): void',
            'registerPanel(AzGuard\\Panels\\Panel|callable $panel): void',
            'revoke(Illuminate\\Contracts\\Auth\\Authenticatable $user, UnitEnum|string $permissionKey, BackedEnum|string|null $panelId = default): int',
            'setCurrentPanel(?AzGuard\\Panels\\Panel $panel): void',
            'tryPermission(BackedEnum|string $panelId, UnitEnum|string $permission): ?string',
        ],
    ],
    'AzGuard\\Testing\\FakeAzGuardUser' => [
        'kind' => 'class',
        'methods' => [
            '__construct(string|int $id = default)',
            'checkPermission(UnitEnum|string $permission, BackedEnum|string|null $panelId = default, ?AzGuard\\Contracts\\PermissionContext $context = default): bool',
            'flushPermissions(BackedEnum|string|null $panelId = default): void',
            'getAuthIdentifier(): string|int',
            'getAuthIdentifierName(): string',
            'getAuthPassword(): string',
            'getAuthPasswordName(): string',
            'getRememberToken(): string',
            'getRememberTokenName(): string',
            'grant(BackedEnum|string $panelId, UnitEnum|string ...$permissions): self',
            'hasContextGuard(): bool',
            'hasPermission(UnitEnum|string $permission, BackedEnum|string|null $panelId = default, ?AzGuard\\Contracts\\PermissionContext $context = default): bool',
            'hasPermissionIn(string $contextType, string|int $contextId, UnitEnum|string $permission, BackedEnum|string|null $panelId = default): bool',
            'isSuperAdmin(BackedEnum|string|null $panelId = default): bool',
            'permissionSet(BackedEnum|string|null $panelId = default): AzGuard\\Registry\\Values\\PermissionSet',
            'permissions(BackedEnum|string|null $panelId = default): Illuminate\\Support\\Collection',
            'setRememberToken($value): void',
            'wildcard(): self',
        ],
    ],
    'AzGuard\\Testing\\FakeGrantSource' => [
        'kind' => 'class',
        'methods' => [
            'grant(BackedEnum|string $panelId, UnitEnum|string ...$permissions): self',
            'permissionsFor(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $panelId): AzGuard\\Registry\\Values\\PermissionSet',
            'priority(): int',
            'wildcard(): self',
        ],
    ],
    'AzGuard\\Testing\\Recorded' => [
        'kind' => 'class',
        'methods' => [
            '__construct(Illuminate\\Contracts\\Auth\\Authenticatable $user, string $key, ?string $panelId = default, ?bool $result = default)',
        ],
    ],
];
