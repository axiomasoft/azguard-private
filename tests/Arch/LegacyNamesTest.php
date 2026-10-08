<?php

declare(strict_types=1);

/**
 * The frozen 0.3 reference was removed in P6.9. This guard keeps its names from returning
 * to the 1.0 sources, tests, launchers, composer manifest, CI and configuration.
 * The 0.3 tree stays readable through git (see the P6.9 section of findings-P6-execution).
 */
const LEGACY_NAMES_FORBIDDEN = [
    // Removed API, config keys, aliases and commands (13 F6, 03 renames).
    'GrantBuilder', 'ContextGrantBuilder', 'HasScopedRoles', 'PolicyAttributeRegistrar',
    'AbilitiesResolver', 'NullSafeUniqueIndex', 'AzGuardManagerInterface', 'SkipGuardCheck',
    'azguard.grant', 'azguard.panel_check', 'azguard.roles', 'check.access',
    'make:guard-panel', 'make:guard-domain', 'make:guard-permission', 'require_permission_attributes',
    'azguard/context',
    // 0.3 classes (legacy/0.3/packages/*/src) that have no 1.0 counterpart.
    'AssignmentDeduplicator', 'AuthorizationContextManager', 'AuthorizesPermission',
    'AzGuardContextServiceProvider', 'AzGuardDiagnostics', 'AzGuardUser', 'BladeHelper',
    'CatalogKeyMatcher', 'CatalogRolePermissionValidator', 'CheckAccess', 'CheckDirectGrant',
    'ClassRoleGrantSource', 'CompositePermissionCatalog', 'ContextGrantCommand', 'ContextGrantGiven',
    'ContextGrantRevoked', 'ContextGuard', 'ContextNotSetException', 'ContextOnlyStrategy',
    'ContextPackageNotInstalledException', 'ContextPermissionLayer', 'ContextRevokeCommand',
    'CreateDirectGrant', 'CurrentPanelState', 'DatabaseRoleGrantSource', 'DefaultAbilitiesResolver',
    'DenyWithoutContextStrategy', 'DirectGrantPolicy', 'DirectGrantResource', 'DirectGrantSource',
    'EffectivePermissionResolver', 'EnumPermissionCatalogBuilder', 'EnumPermissionDefinition',
    'FakeAzGuardUser', 'FakeGrantSource', 'FilamentPermissionCatalogBuilder',
    'GenerateFilamentPermissionsCommand', 'GlobalPlusContextStrategy', 'GrantPriority', 'GrantSource',
    'GuardPolicy', 'GuardScaffoldGenerator', 'HasAzGuardPage', 'HasAzGuardWidget', 'HasDirectGrants',
    'HierarchicalPermissionMatcher', 'IdentityIndexException', 'InvalidCacheConfigException',
    'InvalidModelConfigException', 'InvalidMorphTypeException', 'InvalidPermissionSyntaxException',
    'InvalidRoleClassException', 'InvalidRoleIdentityException', 'ListDirectGrants',
    'ListScopedRolesCommand', 'LoadAzGuardRoles', 'MakeGuardAbilitiesCommand', 'MakeGuardDomainCommand',
    'MakeGuardPanelCommand', 'MakeGuardPermissionCommand', 'MakeGuardPolicyCommand', 'MakeGuardRoleCommand',
    'MissingPermissionAttributeException', 'ModelHasScope', 'PageWidgetAccessEvaluator',
    'PanelCheckAccess', 'PanelIdTooLongException', 'PanelNotSetException', 'PermissionLayer',
    'PermissionResolverInterface', 'PermissionStateRevision', 'PolicyAbilityCatalogBuilder',
    'PolicyDiscovery', 'PolicyGenerator', 'PruneGrantsCommand', 'ResolvesContext',
    'ResolvesGateAbilities', 'ResolvesGuardNamespaces', 'ResolvesRole', 'ResolvesUserModel',
    'RevisionedPermissionModelWrites', 'RevokeGrantCommand', 'RolePermissionConnectionException',
    'RolePermissionSelection', 'RolePermissionSyncConflictException', 'RolePermissionSynchronizer',
    'RolePermissionSyncResult', 'RolePermissionValidator', 'RoleSyncPlanner', 'ScopedRoleCache',
    'SetAuthorizationContext', 'SetCurrentPanel', 'SimplePermissionDefinition', 'SimplePermissionMeta',
    'SupportsForcefulGeneration', 'SyncRolesCommand', 'WildcardPermissionMatcher',
];

/**
 * @return list<string>
 */
function legacyNamesScannedFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.'/composer.json'];

    foreach (['packages', 'tests', 'bin', '.github'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules'], true)
                && ! str_starts_with(str_replace('\\', '/', $file->getPathname()), $root.'/tests/Regression/specs'),
        ));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getPathname() !== __FILE__) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('removes the frozen 0.3 reference tree', function (): void {
    expect(dirname(__DIR__, 2).'/legacy')->not->toBeDirectory();
});

it('keeps the frozen 0.3 tree out of every composer autoload map', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = [];

    foreach ([
        require $root.'/vendor/composer/autoload_psr4.php',
        require $root.'/vendor/composer/autoload_classmap.php',
        require $root.'/vendor/composer/autoload_namespaces.php',
    ] as $map) {
        foreach ($map as $entry) {
            array_push($paths, ...(array) $entry);
        }
    }

    expect(array_filter(
        $paths,
        static fn (string $path): bool => str_contains(str_replace('\\', '/', $path), '/legacy/'),
    ))->toBeEmpty();
});

it('has no 0.3 names in sources, tests, launchers, composer manifest, CI or configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $pattern = '/(?<![\w.:\/-])('.implode('|', array_map(
        static fn (string $name): string => preg_quote($name, '/'),
        LEGACY_NAMES_FORBIDDEN,
    )).')(?![\w-])/';
    $hits = [];

    foreach (legacyNamesScannedFiles() as $path) {
        $contents = file_get_contents($path);

        if ($contents !== false && preg_match_all($pattern, $contents, $matches) > 0) {
            $hits[substr($path, strlen($root) + 1)] = array_values(array_unique($matches[1]));
        }
    }

    expect($hits)->toBe([]);
});

it('has no Blade @az directives', function (): void {
    $hits = [];

    foreach (legacyNamesScannedFiles() as $path) {
        if (str_ends_with($path, '.blade.php') && preg_match('/@az\w*/', (string) file_get_contents($path)) === 1) {
            $hits[] = $path;
        }
    }

    expect($hits)->toBe([]);
});
