<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\MissingPermissionCheckException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Exceptions\PluginDependencyMissingException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Exceptions\WriterConflictException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Configuration\AttributedConnectionRoleGrant;
use AzGuard\Tests\Fixtures\Configuration\AttributedTableRoleGrant;
use AzGuard\Tests\Fixtures\Configuration\WrongTableRoleGrant;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Permissions\AttributedClientPolicy;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Permissions\ClientPolicy;
use AzGuard\Tests\Fixtures\Plugins\AuditTrailPlugin;
use AzGuard\Tests\Fixtures\Plugins\ReportsPlugin;
use AzGuard\Tests\Fixtures\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Roles\OtherManagerRole;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ReaderRole;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use AzGuard\Tests\Fixtures\Sources\WriterSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * Every row of the table of startup checks in 07 §5, as one case that builds the smallest configuration or panel
 * which fails it: the code of the exception, its class and a part of the message that names what is wrong. A check
 * that fails only when a request arrives (`missing_permission_check`) runs the request of the route-check scenario.
 * Configuration and panel errors fail in every environment, so the cases do not touch the environment.
 */

/**
 * The defaults of the configuration compiled into the settings of a panel, the way the registry does it.
 *
 * @param  array<string, mixed>  $defaults
 */
function settingsWithDefaults(array $defaults): void
{
    config(['azguard.defaults' => $defaults]);
    $config = AzGuardConfig::fromRepository(app('config'));
    (new PanelCompiler(static fn (): array => $config->defaults()))->settings(new PanelRecipe('admin'));
}

/**
 * @param  array<string, mixed>  $azguard
 */
function loadConfiguration(array $azguard): AzGuardConfig
{
    config(['azguard' => $azguard]);

    return AzGuardConfig::fromRepository(app('config'));
}

/**
 * @param  list<mixed>  $sources
 */
function adminWith(array $sources): PanelRegistry
{
    return PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false)->permissions($sources),
    ])[2];
}

/**
 * @return array<string, array{0: string, 1: class-string<AzGuardException>, 2: string, 3: Closure(): mixed}>
 */
function bootChecks(): array
{
    return [
        'host_keys outside the list, globally' => [
            'invalid_configuration.host_keys', InvalidConfigurationException::class, 'Host keys must be',
            fn () => loadConfiguration(['ids' => ['host_keys' => 'int']]),
        ],
        'host_keys outside the list, of a storage' => [
            'invalid_configuration.host_keys', InvalidConfigurationException::class, 'Host keys must be',
            fn () => loadConfiguration(['storages' => ['default' => ['host_keys' => 'int']]]),
        ],
        'a persistent cache store without a ttl' => [
            'invalid_configuration.cache_ttl', InvalidConfigurationException::class, 'cache.ttl',
            fn () => settingsWithDefaults(['cache' => ['store' => 'redis', 'ttl' => null]]),
        ],
        'reads outside the enum' => [
            'invalid_configuration.enum', InvalidConfigurationException::class, 'consistency.reads',
            fn () => settingsWithDefaults(['consistency' => ['reads' => 'replica']]),
        ],
        'state_refresh outside the enum' => [
            'invalid_configuration.enum', InvalidConfigurationException::class, 'consistency.state_refresh',
            fn () => settingsWithDefaults(['consistency' => ['state_refresh' => 'always']]),
        ],
        'a gate mode other than authoritative' => [
            'invalid_configuration.enum', InvalidConfigurationException::class, 'gate.mode',
            fn () => settingsWithDefaults(['gate' => ['mode' => 'permissive']]),
        ],
        'a model that does not extend the base model of its kind' => [
            'storage_mismatch', StorageMismatchException::class, 'concrete subclass',
            fn () => DatabaseSource::make()->models(roleGrant: PermissionGrant::class),
        ],
        'a model that is not a model' => [
            'storage_mismatch', StorageMismatchException::class, 'must extend',
            function (): void {
                app(StorageSchema::class)->create('default');
                app(StorageRegistry::class)->get('default')->model('role_grant', Model::class);
            },
        ],
        'a source model bound to another table at freeze' => [
            'storage_mismatch', StorageMismatchException::class, 'declares table',
            fn () => adminWith([DatabaseSource::make()->models(roleGrant: WrongTableRoleGrant::class)]),
        ],
        'a source model with a foreign Table attribute at freeze' => [
            'storage_mismatch', StorageMismatchException::class, 'declares table',
            fn () => adminWith([DatabaseSource::make()->models(roleGrant: AttributedTableRoleGrant::class)]),
        ],
        'a source model with a foreign Connection attribute at freeze' => [
            'storage_mismatch', StorageMismatchException::class, 'declares connection',
            fn () => adminWith([DatabaseSource::make()->models(roleGrant: AttributedConnectionRoleGrant::class)]),
        ],
        'a source that names an unknown storage' => [
            'invalid_configuration.storage', InvalidConfigurationException::class, 'Unknown storage nope',
            fn () => adminWith([DatabaseSource::make()->storage('nope')]),
        ],
        'a source name nobody registered' => [
            'unknown_source', UnknownSourceException::class, 'names the source "ldap"',
            fn () => adminWith(['ldap']),
        ],
        'two writer sources on a panel' => [
            'writer_conflict', WriterConflictException::class, '"audit"',
            fn () => adminWith([new WriterSource('database'), new WriterSource('audit')]),
        ],
        'two sources that define a permission differently' => [
            'duplicate_permission', DuplicatePermissionException::class, '"orders.view"',
            fn () => adminWith([
                new StaticSource('a', [StaticSource::grants('orders.view', 'View orders')]),
                new StaticSource('b', [StaticSource::grants('orders.view', 'Show orders')]),
            ]),
        ],
        'two sources that claim one id' => [
            'duplicate_permission', DuplicatePermissionException::class, 'two sources with the id',
            fn () => adminWith([new StaticSource('same'), new StaticSource('same')]),
        ],
        'two roles that claim one key' => [
            'duplicate_role', DuplicateRoleException::class, 'Role key "manager"',
            fn () => adminWith([new StaticSource('crm', [StaticSource::grants('clients.view')], [new ManagerRole, new OtherManagerRole])]),
        ],
        'two default panels of one model' => [
            'default_panel_conflict', DefaultPanelConflictException::class, '"admin" and "cabinet"',
            fn () => PanelWorld::compile([
                AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default(),
                CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default(),
            ]),
        ],
        'a panel that requires scope membership without an adapter' => [
            'invalid_configuration.membership', InvalidConfigurationException::class, 'membership adapter',
            fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)
                ->scopes(AssignmentScopePolicy::inherit(Project::class)->requireMembership()),
            ]),
        ],
        'a static role of a relation that the panel does not have' => [
            'unknown_role', UnknownRoleException::class, 'unknown static role "ghost"',
            function (): void {
                RelationWorld::seed();
                RelationWorld::compile([RelationSource::make(new ProjectDefinition, 'members', 'ghost')]);
            },
        ],
        'a permission bound to two policies' => [
            'duplicate_policy_binding', DuplicatePolicyBindingException::class, 'clients.view_own_profile',
            fn () => adminWith([new StaticSource('crm', ClientPermission::definitions(), policies: [
                PolicyBinding::for(ClientPermission::ViewOwnProfile, AttributedClientPolicy::class),
                PolicyBinding::for('clients.view_own_profile', ClientPolicy::class),
            ])]),
        ],
        'a prefix that is the first segment of a permission name' => [
            'prefix_conflict', PrefixConflictException::class, 'prefix "orders"',
            fn () => PanelWorld::compile([
                AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('app', 'orders.view')]),
                CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('orders'),
            ]),
        ],
        'a policy-only permission without a policy binding' => [
            'invalid_policy_structure', InvalidPolicyStructureException::class, 'clients.view_own_profile',
            fn () => adminWith([new StaticSource('crm', ClientPermission::definitions())]),
        ],
        'a required tenant whose scope has no owner' => [
            'invalid_configuration.tenant_scope', InvalidConfigurationException::class, 'authoritative tenant owner',
            function (): void {
                Relation::morphMap(['project' => Project::class], false);
                PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)
                    ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
                    ->scopes(AssignmentScopePolicy::inherit(Project::class)),
                ]);
            },
        ],
        'a required tenant without a membership resolver' => [
            'invalid_configuration.tenant_scope', InvalidConfigurationException::class, 'membership adapter',
            fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)
                ->tenants(TenantPolicy::required(Organization::class)),
            ]),
        ],
        'a role bound to a scope the panel does not register' => [
            'invalid_configuration.tenant_scope', InvalidConfigurationException::class, 'unregistered or conflicting assignment scope',
            fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)
                ->roles([ReaderRole::class]),
            ]),
        ],
        'one physical storage under two ids' => [
            'storage_mismatch', StorageMismatchException::class, 'same connection and table prefix',
            function (): void {
                $registry = app(StorageRegistry::class);
                $registry->register(new Storage('alias', $registry->get('default')->connection(), 'azg_'));
            },
        ],
        'a storage whose stored host keys differ from the configuration' => [
            'storage_mismatch', StorageMismatchException::class, 'expected',
            function (): void {
                app(StorageSchema::class)->create('default');
                config()->set('azguard.storages.default.host_keys', 'uuid');
                app()->forgetInstance(AzGuardConfig::class);
                app()->forgetInstance(StorageRegistry::class);
                app(StorageRegistry::class)->get('default')->state('admin');
            },
        ],
        'two plugins that set one setting differently' => [
            'plugin_conflict', PluginConflictException::class, 'cache.ttl',
            function (): void {
                $recipe = new PanelRecipe('admin');
                $builder = new PanelBuilder($recipe);
                $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $builder->cache(ttl: 60));
                $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $builder->cache(ttl: 600));
                (new PanelCompiler)->settings($recipe);
            },
        ],
        'a plugin whose dependency the panel does not have' => [
            'plugin_dependency_missing', PluginDependencyMissingException::class, '"acme/audit-trail"',
            function (): void {
                $registry = new PanelRegistry(app());
                $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([AuditTrailPlugin::make()]));
                AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ReportsPlugin::class])->withoutPlugins(['acme/audit-trail']));
                $registry->register(AdminPanel::class);
                $registry->freeze();
            },
        ],
        'configurePanel() for a panel nobody registered' => [
            'unknown_panel', UnknownPanelException::class, '"ghost"',
            function (): void {
                $registry = new PanelRegistry(app());
                $registry->configure('ghost', static fn (PanelBuilder $panel): PanelBuilder => $panel);
                $registry->freeze();
            },
        ],
        'an action without a check in strict mode' => [
            'missing_permission_check', MissingPermissionCheckException::class, 'Route action',
            function (): void {
                HttpWorld::seed();
                HttpWorld::panel(static fn (PanelBuilder $panel) => $panel->requireRouteChecks());
                Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->get('/closure', static fn (): string => 'closure');
                HttpWorld::actingAs(1);
                test()->withoutExceptionHandling()->get('/closure', ['X-Tenant' => '1']);
            },
        ],
    ];
}

afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
    AdminPanel::reset();
});

it('fails the startup check with the code, class and message of its row in 07 §5', function (string $code, string $class, string $message, Closure $trigger): void {
    try {
        $trigger();
    } catch (AzGuardException $error) {
        expect($error)->toBeInstanceOf($class)
            ->and($error->code())->toBe($code)
            ->and($error->getMessage())->toContain($message);

        return;
    }

    $this->fail('The configuration for the check "'.$code.'" was accepted.');
})->with(bootChecks());

it('covers every code of the table of startup checks in 07 §5', function (): void {
    $covered = array_values(array_unique(array_column(bootChecks(), 0)));
    sort($covered);

    expect($covered)->toBe([
        'default_panel_conflict', 'duplicate_permission', 'duplicate_policy_binding', 'duplicate_role',
        'invalid_configuration.cache_ttl', 'invalid_configuration.enum', 'invalid_configuration.host_keys',
        'invalid_configuration.membership', 'invalid_configuration.storage', 'invalid_configuration.tenant_scope',
        'invalid_policy_structure', 'missing_permission_check', 'plugin_conflict', 'plugin_dependency_missing',
        'prefix_conflict', 'storage_mismatch', 'unknown_panel', 'unknown_role', 'unknown_source', 'writer_conflict',
    ]);
});

it('raises the startup errors in production as well', function (string $code, string $class, string $message, Closure $trigger): void {
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $trigger();
    } catch (AzGuardException $error) {
        expect($error->code())->toBe($code);

        return;
    }

    $this->fail('The configuration for the check "'.$code.'" was accepted in production.');
})->with(array_filter(bootChecks(), static fn (array $row): bool => $row[0] !== 'missing_permission_check'));
