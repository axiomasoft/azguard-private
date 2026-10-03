<?php

declare(strict_types=1);

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Permissions\ClientPolicy;
use AzGuard\Tests\Fixtures\Permissions\OtherClientPolicy;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    StaticSource::$reads = [];
});

/**
 * The admin panel compiled with the given `permissions([...])` items.
 *
 * @param  list<mixed>  $permissions
 */
function catalogOfAdmin(array $permissions): PanelCatalog
{
    return catalogRegistry($permissions)->catalog('admin');
}

/**
 * @param  list<mixed>  $permissions
 */
function catalogRegistry(array $permissions): PanelRegistry
{
    return PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false)->permissions($permissions),
    ])[2];
}

it('collects definitions of static sources by local name in contribution order', function (): void {
    $catalog = catalogOfAdmin([
        StaticSource::names('orders', 'orders.view', 'orders.update'),
        new StaticSource('clients', ClientPermission::definitions(), policies: [PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class)]),
        new ArraySource('plain'),
    ]);

    expect($catalog->panel())->toBe('admin')
        ->and(array_keys($catalog->all()))->toBe(['orders.view', 'orders.update', 'clients.view', 'clients.update', 'clients.view_own_profile'])
        ->and($catalog->get('clients.view')->case)->toBe(ClientPermission::View)
        ->and($catalog->find('orders.view')?->authority)->toBe(PermissionAuthority::Grants);
});

it('finds a permission by its local name or its key of the same panel only', function (): void {
    $catalog = catalogOfAdmin([StaticSource::names('orders', 'orders.view')]);

    expect($catalog->has('orders.view'))->toBeTrue()
        ->and($catalog->has(PermissionKey::of('admin', 'orders.view')))->toBeTrue()
        ->and($catalog->has(PermissionKey::of('cabinet', 'orders.view')))->toBeFalse()
        ->and($catalog->find(PermissionKey::of('cabinet', 'orders.view')))->toBeNull()
        ->and($catalog->has('orders.delete'))->toBeFalse()
        ->and($catalog->has('admin.orders.view'))->toBeFalse()
        ->and(fn () => $catalog->get(PermissionKey::of('cabinet', 'orders.view')))->toThrow(UnknownPermissionException::class, 'cabinet:orders.view')
        ->and(fn () => $catalog->get('orders.delete'))->toThrow(UnknownPermissionException::class, '"orders.delete" is not in the catalog of panel "admin"');
});

it('gives the key an enum case names and refuses an unbound case', function (): void {
    $catalog = catalogOfAdmin([new StaticSource('clients', ClientPermission::definitions(), policies: [PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class)])]);

    expect($catalog->keyOf(ClientPermission::Update)->full())->toBe('admin:clients.update')
        ->and(fn () => $catalog->keyOf(OrderPermission::View))->toThrow(UnknownPermissionException::class, OrderPermission::class.'::View');
});

it('answers lookups of a catalog of 5000 permissions from hash indexes without queries', function (): void {
    $locals = array_map(static fn (int $i): string => 'bulk.action-'.$i, range(1, 5000));
    $catalog = catalogOfAdmin([
        StaticSource::names('bulk', ...$locals),
        new StaticSource('clients', ClientPermission::definitions(), policies: [PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class)]),
    ]);

    DB::enableQueryLog();

    expect($catalog->has('bulk.action-4999'))->toBeTrue()
        ->and($catalog->find('bulk.action-1')?->local)->toBe('bulk.action-1')
        ->and($catalog->has('bulk.action-5001'))->toBeFalse()
        ->and($catalog->keyOf(ClientPermission::View)->local())->toBe('clients.view')
        ->and(DB::getQueryLog())->toBe([])
        ->and($catalog->all())->toHaveCount(5003);

    $indexes = (new ReflectionClass($catalog))->getProperty('definitions')->getValue($catalog);
    expect(array_keys($indexes))->toBe(array_keys($catalog->all()));
});

it('never reads a dynamic source while the panel is built', function (): void {
    $catalog = catalogOfAdmin([StaticSource::names('orders', 'orders.view'), new StaticSource('db', [StaticSource::grants('reports.view')], dynamic: true)]);

    expect(StaticSource::$reads)->toBe(['orders' => 1])
        ->and($catalog->isDynamic())->toBeTrue()
        ->and($catalog->has('reports.view'))->toBeFalse();
});

it('refuses something a source returns that is not a definition', function (): void {
    expect(fn () => catalogOfAdmin([new StaticSource('broken', ['orders.view'])]))
        ->toThrow(InvalidSourceContributionException::class, 'Source "broken" of panel "admin" returned string from permissions()');
});

it('rejects a repeated source id, a malformed id and an unregistered source name', function (array $permissions, string $exception, string $message): void {
    expect(fn () => catalogOfAdmin($permissions))->toThrow($exception, $message);
})->with([
    'repeated id' => [[new ArraySource('ldap'), StaticSource::names('ldap', 'orders.view')], DefinitionException::class, 'two sources with the id "ldap" ('.ArraySource::class.' and '.StaticSource::class.')'],
    'malformed id' => [[new ArraySource('Bad Id')], InvalidIdentityException::class, 'source label'],
    'source name' => [['ldap'], UnknownSourceException::class, 'names the source "ldap", which is not registered'],
]);

it('lays a dynamic overlay over the static part without changing the catalog', function (): void {
    $catalog = catalogOfAdmin([StaticSource::names('orders', 'orders.view')]);
    $tenant = $catalog->withDynamic([StaticSource::grants('reports.view'), StaticSource::grants('reports.view')]);
    $other = $tenant->withDynamic([StaticSource::grants('exports.run')]);

    expect($tenant)->not->toBe($catalog)
        ->and(array_keys($tenant->all()))->toBe(['orders.view', 'reports.view'])
        ->and(array_keys($catalog->all()))->toBe(['orders.view'])
        ->and(array_keys($other->all()))->toBe(['orders.view', 'exports.run'])
        ->and($tenant->get(PermissionKey::of('admin', 'reports.view'))->local)->toBe('reports.view');
});

it('rejects a dynamic permission that is not decided by grants, shadows a static name or starts with the prefix', function (): void {
    $catalog = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('orders', 'orders.view')]),
    ])[2]->catalog('admin');

    expect(fn () => $catalog->withDynamic([new PermissionDefinition('reports.own', PermissionAuthority::Policy)]))
        ->toThrow(InvalidSourceContributionException::class, 'always decided by grants')
        ->and(fn () => $catalog->withDynamic(['reports.view']))->toThrow(InvalidSourceContributionException::class, 'got string')
        ->and(fn () => $catalog->withDynamic([StaticSource::grants('orders.view')]))
        ->toThrow(DuplicatePermissionException::class, 'repeats a static permission of source "orders"')
        ->and(fn () => $catalog->withDynamic([StaticSource::grants('reports.view'), StaticSource::grants('reports.view', 'Other')]))
        ->toThrow(DuplicatePermissionException::class, 'source "dynamic"')
        ->and(fn () => $catalog->withDynamic([StaticSource::grants('admin.reports')]))
        ->toThrow(PrefixConflictException::class, 'starts with the panel prefix "admin"');
});

it('binds policies by case and by name, once per permission', function (): void {
    $catalog = catalogOfAdmin([new StaticSource('clients', ClientPermission::definitions(), policies: [
        PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class),
        PolicyBinding::for('clients.update', ClientPolicy::class),
        PolicyBinding::for(ClientPermission::Update, ClientPolicy::class),
    ])]);

    expect($catalog->bindings())->toBe(['clients.view_own_profile' => ClientPolicy::class, 'clients.update' => ClientPolicy::class]);
});

it('rejects policy bindings that break the authority rules', function (array $policies, string $exception, string $message): void {
    expect(fn () => catalogOfAdmin([new StaticSource('clients', ClientPermission::definitions(), policies: $policies)]))
        ->toThrow($exception, $message);
})->with([
    'policy-only without a binding' => [[], InvalidPolicyStructureException::class, '"clients.view_own_profile" of panel "admin" is decided by its policy alone'],
    'two policies for one permission' => [[
        PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class),
        PolicyBinding::for('clients.view_own_profile', OtherClientPolicy::class),
    ], DuplicatePolicyBindingException::class, 'bound to '.ClientPolicy::class.' by source "clients" and to '.OtherClientPolicy::class],
    'a name outside the catalog' => [[PolicyBinding::for('orders.view', ClientPolicy::class)], UnknownPermissionException::class, '"orders.view" is not in the catalog'],
    'an unbound case' => [[PolicyBinding::for(OrderPermission::View, ClientPolicy::class)], UnknownPermissionException::class, OrderPermission::class.'::View'],
    'not a binding' => [['clients.update'], InvalidSourceContributionException::class, 'from policies()'],
]);

it('restores an equal catalog from its snapshot and refuses a broken one', function (): void {
    $catalog = catalogOfAdmin([
        StaticSource::names('orders', 'orders.view'),
        new StaticSource('clients', ClientPermission::definitions(), policies: [PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class)]),
    ]);
    $snapshot = $catalog->snapshot();
    $restored = PanelCatalog::fromSnapshot($snapshot);

    expect($restored)->not->toBeNull()
        ->and($restored?->snapshot())->toBe($snapshot)
        ->and($restored?->all())->toEqual($catalog->all())
        ->and($restored?->keyOf(ClientPermission::View)->full())->toBe('admin:clients.view')
        ->and($restored?->nameWithFirstSegment('clients'))->toBe('clients.view')
        ->and(PanelCatalog::fromSnapshot([]))->toBeNull()
        ->and(PanelCatalog::fromSnapshot([...$snapshot, 'permissions' => [[...$snapshot['permissions'][0], 'local' => 'Bad']]]))->toBeNull()
        ->and(PanelCatalog::fromSnapshot([...$snapshot, 'permissions' => [[...$snapshot['permissions'][1], 'case' => ['enum' => ClientPermission::class, 'name' => 'Gone']]]]))->toBeNull()
        ->and(PanelCatalog::fromSnapshot([...$snapshot, 'extra' => true]))->toBeNull();
});
