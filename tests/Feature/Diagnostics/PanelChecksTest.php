<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Diagnostics\Checks\CacheStore;
use AzGuard\Diagnostics\Checks\CatalogCollisions;
use AzGuard\Diagnostics\Checks\DirectWrites;
use AzGuard\Diagnostics\Checks\MembershipConfigured;
use AzGuard\Diagnostics\Checks\PanelsPlugins;
use AzGuard\Diagnostics\Checks\PanelsPolicies;
use AzGuard\Diagnostics\Checks\PanelsRelations;
use AzGuard\Diagnostics\Checks\PanelsSources;
use AzGuard\Diagnostics\Checks\PanelsValid;
use AzGuard\Diagnostics\Checks\PoliciesComplete;
use AzGuard\Diagnostics\Checks\RolesKeys;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\GateMode;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Crm\CrmRoleGrant;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\OrganizationMembership;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Diagnostics\KeylessRole;
use AzGuard\Tests\Fixtures\Diagnostics\NeedsPlugin;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;

/*
 * Every check of the core catalog of doctor checks (except filament.plugin): a passing case and a failing case. A
 * failure the boot already refuses is fed to the check directly, as a compiled panel or catalog that has it.
 */

afterEach(function (): void {
    CrmWorld::resetRuntime();
});

/** @param Closure(PanelBuilder): mixed|null $more */
function checkedPanel(?Closure $more = null): void
{
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static function (PanelBuilder $panel) use ($more): void {
        $panel->for(User::class)->permissions([OrderPermission::class, DatabaseSource::make()]);

        if ($more !== null) {
            $more($panel);
        }
    }]);
}

/** @return list<string> */
function doctorKeys(string $key, bool $production = false): array
{
    return DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: $production), $key));
}

/** A compiled panel as the compiler would build it; only the parts a check reads are given. */
function handBuiltPanel(string $id = 'test', ?PanelSettings $settings = null, ?TenantPolicy $tenants = null, bool $default = false): Panel
{
    return new Panel(
        id: $id,
        label: $id,
        default: $default,
        settings: $settings ?? new PanelSettings(null, GateMode::Authoritative, null, 3600, 1, Reads::Primary, StateRefresh::Request, false,
            array_fill_keys(array_keys(PanelSettings::DEFAULTS), 'default')),
        subjectModels: [User::class],
        pluginIds: [],
        tenantPolicy: $tenants,
    );
}

/** The catalog of the notes panel with its snapshot changed. */
function changedNotesCatalog(Closure $change): PanelCatalog
{
    $snapshot = $change(app(PanelRegistry::class)->catalog('notes')->snapshot());
    $catalog = PanelCatalog::fromSnapshot($snapshot);
    expect($catalog)->not->toBeNull();

    return $catalog;
}

it('config.valid passes the configuration as it is and fails a value it would refuse to load', function (): void {
    checkedPanel();
    expect(doctorKeys('config.valid'))->toBe([]);

    config(['azguard.defaults.consistency.reads' => 'eventual']);
    $finding = DoctorWorld::only(DoctorWorld::run(), 'config.valid');
    expect(DoctorWorld::summary($finding))->toBe(['core config.valid error'])
        ->and($finding[0]->details)->toBe(['code' => 'invalid_configuration.enum', 'setting' => 'consistency.reads']);

    config(['azguard.defaults.consistency.reads' => 'primary', 'azguard.unknown_section' => true]);
    expect(DoctorWorld::only(DoctorWorld::run(), 'config.valid')[0]->details)->toBe(['code' => 'invalid_configuration']);
});

it('gate.mode passes an authoritative panel and fails a default mode the configuration changed', function (): void {
    checkedPanel();
    expect(doctorKeys('gate.mode'))->toBe([]);

    config(['azguard.defaults.gate.mode' => 'permissive']);
    expect(doctorKeys('gate.mode'))->toBe(['panel:test gate.mode error']);
});

it('cache.store passes a store with a ttl or no store, and fails a persistent store without a ttl', function (): void {
    $settings = static fn (?string $store, ?int $ttl): PanelSettings => new PanelSettings(null, GateMode::Authoritative, $store, $ttl, 1,
        Reads::Primary, StateRefresh::Request, false, array_fill_keys(array_keys(PanelSettings::DEFAULTS), 'configure'));

    checkedPanel(static fn (PanelBuilder $panel) => $panel->cache(store: 'array', ttl: 60));
    expect(doctorKeys('cache.store'))->toBe([])
        ->and(CacheStore::check(handBuiltPanel(settings: $settings(null, null))))->toBe([])
        ->and(DoctorWorld::summary(CacheStore::check(handBuiltPanel(settings: $settings('redis', null)))))->toBe(['panel:test cache.store error']);
});

it('consistency.reads passes primary reads and warns about default reads on a connection with read hosts', function (): void {
    config([
        'database.connections.replicated' => [...config('database.connections.testbench'), 'read' => ['database' => ':memory:'], 'write' => []],
        'azguard.storages' => ['default' => [], 'replicated' => ['connection' => 'replicated']],
    ]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    DoctorWorld::migrate('replicated');
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()->storage('replicated')])]);
    expect(doctorKeys('consistency.reads'))->toBe([]);

    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()->storage('replicated')])->consistency(reads: Reads::Default)]);
    expect(doctorKeys('consistency.reads'))->toBe(['panel:test consistency.reads warning']);
});

it('membership.configured passes a creatable membership and fails one that cannot be created; warns about inherit without membership', function (): void {
    expect(MembershipConfigured::check(handBuiltPanel(tenants: TenantPolicy::required(Organization::class)->requireMembership(new OrganizationMembership)), app()))->toBe([])
        ->and(DoctorWorld::summary(MembershipConfigured::check(handBuiltPanel(tenants: TenantPolicy::required(Organization::class)->requireMembership()), app())))
        ->toBe(['panel:test membership.configured error']);

    CrmWorld::seed();
    CrmWorld::compile();
    expect(doctorKeys('membership.configured'))->toBe(['panel:backoffice membership.configured warning', 'panel:crm membership.configured warning']);
});

it('direct_writes passes models that extend the write-guarded bases and fails a model that does not', function (): void {
    checkedPanel();
    expect(doctorKeys('direct_writes'))->toBe([]);

    config(['azguard.defaults.models.permission' => User::class]);
    expect(doctorKeys('direct_writes'))->toBe(['core direct_writes error'])
        ->and(DoctorWorld::summary(DirectWrites::check('role_grant', CrmRoleGrant::class)))->toBe([]);
});

it('catalog.build_id warns only for a production run without an explicit build id', function (): void {
    checkedPanel();
    expect(doctorKeys('catalog.build_id'))->toBe([])
        ->and(doctorKeys('catalog.build_id', production: true))->toBe(['core catalog.build_id warning']);

    config(['azguard.catalog.build_id' => 'release-42']);
    expect(doctorKeys('catalog.build_id', production: true))->toBe([]);
});

it('catalog.cached warns in production without a cache and for a panel whose catalog is not the cached one', function (): void {
    $path = sys_get_temp_dir().'/azguard-doctor-'.bin2hex(random_bytes(4)).'.php';
    config(['azguard.catalog.cache_path' => $path, 'azguard.catalog.build_id' => 'release-42']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    DoctorWorld::migrate();
    DoctorWorld::notes();

    try {
        expect(doctorKeys('catalog.cached'))->toBe([])
            ->and(doctorKeys('catalog.cached', production: true))->toBe(['core catalog.cached warning']);

        Artisan::call('azguard:catalog:cache');
        DoctorWorld::notes();
        expect(doctorKeys('catalog.cached', production: true))->toBe([]);

        DoctorWorld::notes(static fn (PanelBuilder $panel) => $panel->permissions([OrderPermission::class]));
        expect(doctorKeys('catalog.cached', production: true))->toBe(['panel:notes catalog.cached warning']);
    } finally {
        @unlink($path);
    }
});

it('catalog.collisions passes distinct names and fails a run-time permission that repeats a code permission', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()->dynamicPermissions()])]);
    expect(doctorKeys('catalog.collisions'))->toBe([]);

    $local = (string) array_key_first(app(PanelRegistry::class)->catalog('test')->all());
    app(StorageRegistry::class)->get('default')->mutate('test', static function ($mutation) use ($local): void {
        $mutation->table('permissions')->insert(['panel' => 'test', 'tenant_key' => 'global', 'name' => $local]);
    });

    $finding = DoctorWorld::only(DoctorWorld::run(), 'catalog.collisions');
    expect(DoctorWorld::summary($finding))->toBe(['panel:test catalog.collisions error'])
        ->and($finding[0]->details['tenant'])->toBe('global');
});

it('catalog.collisions fails two panels with one permission prefix', function (): void {
    $prefixed = static fn (string $id): Panel => handBuiltPanel($id, new PanelSettings('shop', GateMode::Authoritative, null, 3600, 1, Reads::Primary,
        StateRefresh::Request, false, array_fill_keys(array_keys(PanelSettings::DEFAULTS), 'provider')));

    expect(CatalogCollisions::prefixes(['admin' => $prefixed('admin'), 'cabinet' => handBuiltPanel('cabinet')], []))->toBe([])
        ->and(DoctorWorld::summary(CatalogCollisions::prefixes(['admin' => $prefixed('admin'), 'cabinet' => $prefixed('cabinet')], [])))
        ->toBe(['core catalog.collisions error']);
});

it('panels.valid passes registered panels and fails a missing storage, a foreign provider and two defaults of one model', function (): void {
    checkedPanel();
    expect(doctorKeys('panels.valid'))->toBe([]);

    config(['azguard.storages' => ['other' => []]]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    $missing = DoctorWorld::only(DoctorWorld::run(), 'panels.valid');
    expect(DoctorWorld::summary($missing))->toBe(['panel:test panels.valid error'])
        ->and($missing[0]->details)->toBe(['code' => 'invalid_configuration.storage'])
        ->and(DoctorWorld::summary(PanelsValid::check(handBuiltPanel(), stdClass::class)))->toBe(['panel:test panels.valid error'])
        ->and(DoctorWorld::summary(PanelsValid::defaults([
            'admin' => handBuiltPanel('admin', default: true), 'cabinet' => handBuiltPanel('cabinet', default: true),
        ])))->toBe(['core panels.valid error']);
});

it('panels.valid fails a subject model without a morph alias before a check fails on it', function (): void {
    checkedPanel();
    Relation::morphMap([], false);

    $findings = DoctorWorld::only(DoctorWorld::run(), 'panels.valid');

    expect(DoctorWorld::summary($findings))->toBe(['panel:test panels.valid error'])
        ->and($findings[0]->details)->toBe(['model' => User::class])
        ->and($findings[0]->message)->toContain('without a morph alias')->toContain('Relation::enforceMorphMap');
});

it('panels.plugins passes attached dependencies and fails a missing dependency and conflicting plugin settings', function (): void {
    checkedPanel();
    expect(doctorKeys('panels.plugins'))->toBe([]);

    $recipe = new PanelRecipe('test');
    $recipe->during(PanelRecipe::provider(), static fn () => $recipe->record(PanelRecipe::PLUGINS, [new NeedsPlugin]));
    $recipe->during(PanelRecipe::plugin('acme/one', 0), static fn () => $recipe->record(PanelSettings::CACHE_TTL, 10));
    $recipe->during(PanelRecipe::plugin('acme/two', 1), static fn () => $recipe->record(PanelSettings::CACHE_TTL, 20));

    expect(DoctorWorld::summary(PanelsPlugins::check($recipe, ['acme/needs', 'acme/one', 'acme/two'], app())))
        ->toBe(['panel:test panels.plugins error', 'panel:test panels.plugins error'])
        ->and(PanelsPlugins::check($recipe, ['acme/needs', 'acme/base'], app()))->toHaveCount(1);
});

it('panels.sources passes distinct sources with one writer and fails repeated ids, two writers and a foreign class', function (): void {
    checkedPanel(static fn (PanelBuilder $panel) => $panel->permissions([new ArraySource('array')]));
    expect(doctorKeys('panels.sources'))->toBe([]);

    $writer = static fn (string $id): SourceDescription => new SourceDescription($id, DatabaseSource::class, [StoresGrants::class], false);
    expect(array_map(static fn (DoctorFinding $finding): string => $finding->message, PanelsSources::check('test', [
        $writer('database'), $writer('database'), new SourceDescription('odd', stdClass::class, [], false),
    ])))->toBe([
        'Panel test has the source odd of stdClass, which is not a source.',
        'Panel test has 2 sources with the id "database".',
        'Panel test has more than one source that stores grants: database, database.',
    ]);
});

it('panels.policies passes bound policy methods and fails a binding to a method that is gone', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::notes();
    expect(doctorKeys('panels.policies'))->toBe([]);

    $catalog = changedNotesCatalog(static function (array $snapshot): array {
        $snapshot['policy_bindings']['note.publish']['method'] = 'vanished';
        $snapshot['binding_methods']['note.publish'] = 'vanished';

        return $snapshot;
    });
    expect(DoctorWorld::summary(PanelsPolicies::check($catalog)))->toBe(['panel:notes panels.policies error']);
});

it('panels.policies of the folder source warns when the catalog cache records another discovery than the folders give', function (): void {
    $path = sys_get_temp_dir().'/azguard-doctor-'.bin2hex(random_bytes(4)).'.php';
    config(['azguard.catalog.cache_path' => $path, 'azguard.catalog.build_id' => 'release-42']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    DoctorWorld::migrate();
    DoctorWorld::notes();

    try {
        Artisan::call('azguard:catalog:cache');
        expect(doctorKeys('panels.policies'))->toBe([]);

        $file = app(CatalogCache::class)->read();
        array_pop($file['panels']['notes']['discovery']['bindings']);
        $file['panels']['notes']['discovery']['files'] = array_map(static fn (): string => str_repeat('0', 64), $file['panels']['notes']['discovery']['files']);
        app(CatalogCache::class)->write($file['build_id'], $file['panels']);

        $finding = DoctorWorld::only(DoctorWorld::run(), 'panels.policies');
        expect(DoctorWorld::summary($finding))->toBe(['panel:notes panels.policies warning'])
            ->and($finding[0]->details)->toBe(['changed' => ['bindings', 'files']]);
    } finally {
        @unlink($path);
    }
});

it('policies.complete warns about a public policy method that decides nothing and fails a policy-only permission without a method', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::notes();
    $finding = DoctorWorld::only(DoctorWorld::run(), 'policies.complete');
    expect(DoctorWorld::summary($finding))->toBe(['panel:notes policies.complete warning'])
        ->and($finding[0]->details['method'])->toBe('draft');

    $catalog = changedNotesCatalog(static function (array $snapshot): array {
        unset($snapshot['policy_bindings']['note.publish'], $snapshot['bindings']['note.publish'], $snapshot['binding_methods']['note.publish']);

        return $snapshot;
    });
    expect(DoctorWorld::summary(PoliciesComplete::check($catalog)))->toBe(['panel:notes policies.complete error']);
});

it('policies.complete passes policies whose public methods decide permissions or implement an interface of the policy', function (): void {
    CrmWorld::seed();
    CrmWorld::compile();

    expect(doctorKeys('policies.complete'))->toBe([]);
});

it('roles.keys passes roles with stable keys and fails a role without one or with a key the catalog does not store', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::notes();
    expect(doctorKeys('roles.keys'))->toBe([]);

    $catalog = changedNotesCatalog(static function (array $snapshot): array {
        $snapshot['roles']['editor']['class'] = KeylessRole::class;
        $snapshot['roles']['seller'] = [...$snapshot['roles']['editor'], 'class' => SellerRole::class, 'key' => 'seller'];
        $snapshot['roles']['renamed'] = [...$snapshot['roles']['editor'], 'class' => SellerRole::class, 'key' => 'renamed'];

        return $snapshot;
    });
    expect(array_map(static fn (DoctorFinding $finding): string => $finding->details['role'].' '.($finding->details['key'] ?? '-'), RolesKeys::check($catalog, app())))
        ->toBe([KeylessRole::class.' -', SellerRole::class.' renamed']);
});

it('panels.relations passes a relation role of the panel and fails one the panel does not have', function (): void {
    CrmWorld::seed();
    CrmWorld::compile();
    expect(doctorKeys('panels.relations'))->toBe([]);

    $catalog = app(PanelRegistry::class)->catalog('crm');
    expect(PanelsRelations::check([RelationSource::make(ProjectScope::make(), 'users', 'seller')], $catalog))->toBe([])
        ->and(DoctorWorld::summary(PanelsRelations::check([RelationSource::make(ProjectScope::make(), 'users', 'ghost')], $catalog)))
        ->toBe(['panel:crm panels.relations error']);
});

it('runs every panel check of the core for every selected panel only', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::panels([
        AdminPanel::class => static fn (PanelBuilder $panel) => $panel->for([User::class, Vendor::class])->permissions([OrderPermission::class, DatabaseSource::make()])
            ->cache(store: 'array', ttl: 60)->consistency(reads: Reads::Default),
        CabinetPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([InvoicePermission::class]),
    ]);
    config(['azguard.defaults.gate.mode' => 'permissive']);

    expect(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(['cabinet']), 'gate.mode')))->toBe(['panel:cabinet gate.mode error']);
});
