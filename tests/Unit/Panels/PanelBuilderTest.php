<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use AzGuard\Tests\Fixtures\Panels\FixedResourceScopeResolver;
use AzGuard\Tests\Fixtures\Panels\FixedScopeResolver;
use AzGuard\Tests\Fixtures\Panels\FixedTenantResolver;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\Manager;
use AzGuard\Tests\Fixtures\Panels\NumericPermission;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PlainMarker;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Plugins\CacheTtlPlugin;
use AzGuard\Tests\Fixtures\Plugins\ReportsPlugin;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;

/**
 * @return array{0: PanelBuilder, 1: PanelRecipe}
 */
function panelBuilder(string $id = 'admin'): array
{
    $recipe = new PanelRecipe($id);

    return [new PanelBuilder($recipe), $recipe];
}

/**
 * Builder methods that describe a panel without plugins, settings, discovery, tenants or fields.
 */
const PANEL_BUILDER_METHODS = [
    'id', 'label', 'description', 'default', 'resourcePrefix', 'for', 'middleware', 'entry', 'onDenied',
    'requireRouteChecks', 'permissions', 'roles', 'presentation', 'before', 'restrictions', 'after', 'changing',
    'grantConditions', 'doctorChecks', 'tenantResolvers', 'scopeResolvers', 'resourceScopes',
];

it('writes every setter call to the recipe with the provider origin and returns the builder', function (string $method, array $arguments, string $setting, mixed $value): void {
    [$builder, $recipe] = panelBuilder();

    expect($builder->{$method}(...$arguments))->toBe($builder)
        ->and($recipe->records())->toBe([['setting' => $setting, 'value' => $value, 'origin' => PanelRecipe::provider()]]);
})->with([
    'id' => ['id', ['admin'], PanelRecipe::ID, 'admin'],
    'label' => ['label', ['Back office'], PanelRecipe::LABEL, 'Back office'],
    'description' => ['description', [null], PanelRecipe::DESCRIPTION, null],
    'default' => ['default', [], PanelRecipe::DEFAULT, true],
    'default off' => ['default', [false], PanelRecipe::DEFAULT, false],
    'resourcePrefix' => ['resourcePrefix', ['backoffice'], PanelRecipe::RESOURCE_PREFIX, 'backoffice'],
    'resourcePrefix on' => ['resourcePrefix', [], PanelRecipe::RESOURCE_PREFIX, true],
    'resourcePrefix off' => ['resourcePrefix', [false], PanelRecipe::RESOURCE_PREFIX, false],
    'for' => ['for', [User::class, 'web'], PanelRecipe::SUBJECTS, [['model' => User::class, 'guard' => 'web', 'directory' => null]]],
    'middleware' => ['middleware', [['web', 'auth']], PanelRecipe::MIDDLEWARE, ['web', 'auth']],
    'entry' => ['entry', [OrderPermission::View], PanelRecipe::ENTRY, OrderPermission::View],
    'onDenied' => ['onDenied', ['/login'], PanelRecipe::ON_DENIED, '/login'],
    'requireRouteChecks' => ['requireRouteChecks', [], PanelRecipe::REQUIRE_ROUTE_CHECKS, true],
    'permissions' => ['permissions', [[OrderPermission::class, 'ldap']], PanelRecipe::PERMISSIONS, [OrderPermission::class, 'ldap']],
    'roles' => ['roles', [[SellerRole::class]], PanelRecipe::ROLES, [SellerRole::class]],
    'presentation' => ['presentation', [['icon' => 'shield']], PanelRecipe::PRESENTATION, ['icon' => 'shield']],
    'before' => ['before', ['App\Hooks\Before'], PanelRecipe::BEFORE, ['App\Hooks\Before']],
    'restrictions' => ['restrictions', [['App\Restrictions\OfficeHours']], PanelRecipe::RESTRICTIONS, ['App\Restrictions\OfficeHours']],
    'after' => ['after', [['App\Hooks\After']], PanelRecipe::AFTER, ['App\Hooks\After']],
    'changing' => ['changing', [['App\Pipes\Audit']], PanelRecipe::CHANGING, ['App\Pipes\Audit']],
    'grantConditions' => ['grantConditions', [['App\Conditions\Active']], PanelRecipe::GRANT_CONDITIONS, ['App\Conditions\Active']],
    'doctorChecks' => ['doctorChecks', [['App\Checks\Storage']], PanelRecipe::DOCTOR_CHECKS, ['App\Checks\Storage']],
    'tenantResolvers' => ['tenantResolvers', [[FixedTenantResolver::class]], PanelRecipe::TENANT_RESOLVERS, [FixedTenantResolver::class]],
    'scopeResolvers' => ['scopeResolvers', [[FixedScopeResolver::class]], PanelRecipe::SCOPE_RESOLVERS, [FixedScopeResolver::class]],
    'resourceScopes' => [
        'resourceScopes',
        [[Seller::class => FixedResourceScopeResolver::class]],
        PanelRecipe::RESOURCE_SCOPES,
        [['resource' => Seller::class, 'resolver' => FixedResourceScopeResolver::class]],
    ],
    'plugins' => ['plugins', [[ReportsPlugin::class]], PanelRecipe::PLUGINS, [ReportsPlugin::class]],
    'withoutPlugins' => ['withoutPlugins', [['acme/audit']], PanelRecipe::WITHOUT_PLUGINS, ['acme/audit']],
]);

it('has every builder method of the panel description and exposes nothing to read back', function (): void {
    $methods = [];

    foreach ((new ReflectionClass(PanelBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor()) {
            continue;
        }

        $methods[] = $method->getName();

        expect((string) $method->getReturnType())->toBe('static', $method->getName());
    }

    expect(array_values(array_diff(PANEL_BUILDER_METHODS, $methods)))->toBe([]);
});

it('names subjects with for() and takes definitions through a single permissions(array) method', function (): void {
    $builder = new ReflectionClass(PanelBuilder::class);
    $permissions = $builder->getMethod('permissions');

    expect($builder->hasMethod('for'))->toBeTrue()
        ->and($permissions->getNumberOfParameters())->toBe(1)
        ->and((string) $permissions->getParameters()[0]->getType())->toBe('array')
        ->and($builder->hasMethod('sources'))->toBeFalse()
        ->and($builder->hasMethod('subjects'))->toBeFalse()
        ->and($builder->hasMethod('inPanel'))->toBeFalse();
});

it('records the origin that is current when a setter is called', function (): void {
    [$builder, $recipe] = panelBuilder();
    $plugin = PanelRecipe::plugin('acme/audit', 2);

    $builder->label('provider');
    $recipe->during($plugin, fn () => $builder->label('plugin'));
    $recipe->during(PanelRecipe::configure(), fn () => $builder->label('configure'));
    $builder->label('provider again');

    expect(array_column($recipe->records(), 'origin'))->toBe([
        PanelRecipe::provider(), $plugin, PanelRecipe::configure(), PanelRecipe::provider(),
    ])->and($plugin)->toBe(['kind' => 'plugin', 'plugin' => 'acme/audit', 'order' => 2])
        ->and(PanelRecipe::provider())->toBe(['kind' => 'provider', 'plugin' => null, 'order' => 0])
        ->and(PanelRecipe::configure())->toBe(['kind' => 'configure', 'plugin' => null, 'order' => 0])
        ->and((new ReflectionMethod(PanelRecipe::class, 'plugin'))->getNumberOfParameters())->toBe(2);
});

it('tells the origin a record written now would get', function (): void {
    [, $recipe] = panelBuilder();
    $seen = [];

    $recipe->during(PanelRecipe::plugin('acme/audit', 1), function () use ($recipe, &$seen): void {
        $seen[] = $recipe->origin();
    });

    expect($seen)->toBe([PanelRecipe::plugin('acme/audit', 1)])
        ->and($recipe->origin())->toBe(PanelRecipe::provider());
});

it('attaches plugin objects and classes and keeps the order of attachment', function (): void {
    [$builder, $recipe] = panelBuilder();
    $audit = CacheTtlPlugin::make('acme/audit', ttl: 60);
    $global = CacheTtlPlugin::make('acme/global', ttl: 60);
    $nested = CacheTtlPlugin::make('acme/nested', ttl: 60);

    $recipe->during(PanelRecipe::configure(), fn () => $builder->plugins([$global]));
    $builder->plugins([$audit])->plugins(['\\'.ReportsPlugin::class]);
    $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $builder->plugins([$nested]));

    expect($recipe->plugins(PanelRecipe::PROVIDER))->toBe([$audit, ReportsPlugin::class])
        ->and($recipe->plugins(PanelRecipe::CONFIGURE))->toBe([$global])
        ->and($recipe->plugins(PanelRecipe::PLUGIN))->toBe([$nested])
        ->and($recipe->pluginIds())->toBe([]);
});

it('rejects anything but plugin objects and plugin classes in plugins()', function (mixed $plugin): void {
    [$builder, $recipe] = panelBuilder();

    expect(fn () => $builder->plugins([CacheTtlPlugin::make('acme/audit', ttl: 60), $plugin]))->toThrow(DefinitionException::class, 'plugins()')
        ->and($recipe->records())->toBe([]);
})->with([
    'an object that is not a plugin' => [new stdClass],
    'a class that is not a plugin' => [User::class],
    'an unknown class' => ['App\Plugins\Missing'],
    'a plugin id' => ['acme/audit'],
    'a number' => [42],
    'null' => [null],
]);

it('records the plugins a panel keeps off and rejects anything but ids', function (): void {
    [$builder, $recipe] = panelBuilder();

    $builder->withoutPlugins(['acme/audit', 'acme/reports'])->withoutPlugins(['acme/audit']);

    expect($recipe->withoutPlugins())->toBe(['acme/audit', 'acme/reports'])
        ->and(fn () => $builder->withoutPlugins([42]))->toThrow(DefinitionException::class, 'withoutPlugins()')
        ->and(fn () => $builder->withoutPlugins(['']))->toThrow(DefinitionException::class, 'withoutPlugins()')
        ->and(fn () => $builder->withoutPlugins([CacheTtlPlugin::make('acme/audit', ttl: 60)]))->toThrow(DefinitionException::class, 'withoutPlugins()');
});

it('does not take withoutPlugins() from a plugin', function (): void {
    [$builder, $recipe] = panelBuilder();

    $recipe->during(PanelRecipe::configure(), fn () => $builder->withoutPlugins(['acme/audit']));

    expect(fn () => $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $builder->withoutPlugins(['acme/reports'])))
        ->toThrow(DefinitionException::class, 'a plugin cannot detach another plugin')
        ->and($recipe->withoutPlugins())->toBe(['acme/audit']);
});

it('numbers attached plugins from one and refuses to attach to a compiled panel', function (): void {
    [, $recipe] = panelBuilder();

    expect($recipe->attach('acme/audit'))->toBe(PanelRecipe::plugin('acme/audit', 1))
        ->and($recipe->attach('acme/reports'))->toBe(PanelRecipe::plugin('acme/reports', 2))
        ->and($recipe->pluginIds())->toBe(['acme/audit', 'acme/reports']);

    $recipe->seal();

    expect(fn () => $recipe->attach('acme/late'))->toThrow(RegistryFrozenException::class, 'already compiled')
        ->and($recipe->pluginIds())->toBe(['acme/audit', 'acme/reports']);
});

it('restores the origin when the callback throws', function (): void {
    [$builder, $recipe] = panelBuilder();

    try {
        $recipe->during(PanelRecipe::configure(), fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // the origin must be the provider again
    }

    $builder->label('after');

    expect($recipe->records()[0]['origin'])->toBe(PanelRecipe::provider());
});

it('rejects an id that differs from the id of the provider', function (): void {
    [$builder] = panelBuilder('admin');

    expect(fn () => $builder->id('backoffice'))->toThrow(DefinitionException::class, 'getId()');
});

it('refuses every setter once the panel is compiled', function (): void {
    [$builder, $recipe] = panelBuilder();
    $recipe->seal();

    expect(fn () => $builder->label('late'))->toThrow(RegistryFrozenException::class, 'already compiled')
        ->and(fn () => $builder->permissions([OrderPermission::class]))->toThrow(RegistryFrozenException::class)
        ->and($recipe->records())->toBe([]);
});

it('adds subject models over several for() calls', function (): void {
    [$builder, $recipe] = panelBuilder();

    $builder->for(User::class, guard: 'web')->for([Seller::class, Manager::class], directory: 'App\Directories\Sellers');

    expect($recipe->subjects())->toBe([
        ['model' => User::class, 'guard' => 'web', 'directory' => null],
        ['model' => Seller::class, 'guard' => null, 'directory' => 'App\Directories\Sellers'],
        ['model' => Manager::class, 'guard' => null, 'directory' => 'App\Directories\Sellers'],
    ]);
});

it('rejects anything but model classes in for()', function (mixed $model, ?string $guard): void {
    [$builder] = panelBuilder();

    expect(fn () => $builder->for($model, $guard))->toThrow(DefinitionException::class, 'for()');
})->with([
    'empty list' => [[], null],
    'not a model' => [stdClass::class, null],
    'unknown class' => ['App\Models\Missing', null],
    'keyed list' => [['user' => User::class], null],
    'object in the list' => [[new User], null],
    'empty guard' => [User::class, ''],
]);

it('accepts one name segment as a resource prefix', function (string $prefix): void {
    [$builder] = panelBuilder();

    expect(fn () => $builder->resourcePrefix($prefix))->toThrow(DefinitionException::class, 'resourcePrefix()');
})->with(['', 'back.office', 'Back', 'a b', 'a:b', '*']);

it('normalizes mixed permission definitions and keeps an enum class once', function (): void {
    [$builder, $recipe] = panelBuilder();
    $source = new ArraySource('ldap-live');

    $builder->permissions([OrderPermission::class, $source, 'ldap', '\\'.OrderPermission::class])
        ->permissions([InvoicePermission::class, OrderPermission::class, 'database']);

    expect($recipe->enums())->toBe([OrderPermission::class, InvoicePermission::class])
        ->and($recipe->sources())->toBe([$source, 'ldap', 'database']);
});

it('rejects a permission definition that is not a string-backed enum, a source or a source name', function (mixed $definition): void {
    [$builder, $recipe] = panelBuilder();

    expect(fn () => $builder->permissions([OrderPermission::class, $definition]))->toThrow(DefinitionException::class, 'permissions()')
        ->and($recipe->records())->toBe([]);
})->with([
    'class that is not an enum' => [stdClass::class],
    'namespaced class that is not an enum' => [User::class],
    'unknown class' => ['App\Enums\Missing'],
    'pure enum' => [PlainMarker::class],
    'int-backed enum' => [NumericPermission::class],
    'number' => [42],
    'enum case' => [OrderPermission::View],
    'empty string' => [''],
    'array' => [['orders.view']],
    'null' => [null],
]);

it('keeps a role class once and rejects anything that is not a code role', function (): void {
    [$builder, $recipe] = panelBuilder();

    $builder->roles([SellerRole::class, AnalystRole::class])->roles([SellerRole::class]);

    expect($recipe->roles())->toBe([SellerRole::class, AnalystRole::class])
        ->and(fn () => $builder->roles([User::class]))->toThrow(DefinitionException::class, 'roles()')
        ->and(fn () => $builder->roles([new SellerRole]))->toThrow(DefinitionException::class, 'roles()');
});

it('adds hooks instead of replacing them', function (): void {
    [$builder, $recipe] = panelBuilder();
    $closure = static fn (): null => null;

    $builder->before('App\Hooks\First')->before([$closure])->after($closure)->restrictions(['App\Restrictions\A'])
        ->restrictions(['App\Restrictions\B']);

    expect($recipe->items(PanelRecipe::BEFORE))->toBe(['App\Hooks\First', $closure])
        ->and($recipe->items(PanelRecipe::AFTER))->toBe([$closure])
        ->and($recipe->items(PanelRecipe::RESTRICTIONS))->toBe(['App\Restrictions\A', 'App\Restrictions\B']);
});

it('rejects a hook that is neither a class name, an object nor a closure', function (string $method): void {
    [$builder] = panelBuilder();

    expect(fn () => $builder->{$method}([42]))->toThrow(DefinitionException::class, $method.'()')
        ->and(fn () => $builder->{$method}(['']))->toThrow(DefinitionException::class, $method.'()');
})->with(['before', 'restrictions', 'after', 'changing', 'grantConditions', 'doctorChecks']);

it('rejects middleware that is not a name', function (): void {
    [$builder] = panelBuilder();

    expect(fn () => $builder->middleware(['web', 42]))->toThrow(DefinitionException::class, 'middleware()');
});

it('accepts resolver objects and classes of the matching contract only', function (): void {
    [$builder, $recipe] = panelBuilder();
    $tenant = new FixedTenantResolver;
    $resource = new FixedResourceScopeResolver;

    $builder->tenantResolvers([$tenant])->tenantResolvers([FixedTenantResolver::class])
        ->scopeResolvers([new FixedScopeResolver])
        ->resourceScopes([Seller::class => $resource]);

    expect($recipe->items(PanelRecipe::TENANT_RESOLVERS))->toBe([$tenant, FixedTenantResolver::class])
        ->and($recipe->items(PanelRecipe::RESOURCE_SCOPES))->toBe([['resource' => Seller::class, 'resolver' => $resource]])
        ->and(fn () => $builder->tenantResolvers([FixedScopeResolver::class]))->toThrow(DefinitionException::class, 'tenantResolvers()')
        ->and(fn () => $builder->scopeResolvers([$tenant]))->toThrow(DefinitionException::class, 'scopeResolvers()')
        ->and(fn () => $builder->resourceScopes([Seller::class => FixedTenantResolver::class]))->toThrow(DefinitionException::class, 'resourceScopes()')
        ->and(fn () => $builder->resourceScopes([stdClass::class => $resource]))->toThrow(DefinitionException::class, 'resourceScopes()')
        ->and(fn () => $builder->resourceScopes([$resource]))->toThrow(DefinitionException::class, 'resourceScopes()');
});

it('reads list items by layer: provider, plugins by attachment order, then configure', function (): void {
    [$builder, $recipe] = panelBuilder();

    $recipe->during(PanelRecipe::configure(), fn () => $builder->restrictions(['configure']));
    $recipe->during(PanelRecipe::plugin('acme/b', 2), fn () => $builder->restrictions(['plugin b']));
    $recipe->during(PanelRecipe::plugin('acme/a', 1), fn () => $builder->restrictions(['plugin a']));
    $builder->restrictions(['provider 1'])->restrictions(['provider 2']);

    expect($recipe->items(PanelRecipe::RESTRICTIONS))->toBe(['provider 1', 'provider 2', 'plugin a', 'plugin b', 'configure']);
});
