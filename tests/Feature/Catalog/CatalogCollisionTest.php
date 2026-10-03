<?php

declare(strict_types=1);

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Permissions\ClientPolicy;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Roles\ProfileViewerRole;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

/**
 * The admin panel without a prefix: the provider attaches the given sources, each plugin attaches its own.
 *
 * @param  list<mixed>  $sources
 * @param  array<string, list<mixed>>  $pluginSources  plugin id => sources the plugin attaches
 */
function collisionPanel(array $sources, array $pluginSources = []): PanelRegistry
{
    $plugins = [];

    foreach ($pluginSources as $id => $attached) {
        $plugins[] = ProbePlugin::make($id, register: static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions($attached));
    }

    return PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false)->permissions($sources)->plugins($plugins),
    ])[2];
}

it('V08: keeps one permission when two sources define it equally, owned by the first one', function (): void {
    $catalog = collisionPanel([StaticSource::names('a', 'orders.view'), StaticSource::names('b', 'orders.view')])->catalog('admin');

    expect($catalog->all())->toHaveCount(1)
        ->and($catalog->snapshot()['permissions'][0]['source'])->toBe('a');
});

it('V08: rejects two sources that define one name differently and names both', function (): void {
    expect(fn () => collisionPanel([
        new StaticSource('a', [StaticSource::grants('orders.view', 'View orders')]),
        new StaticSource('b', [StaticSource::grants('orders.view', 'Show orders')]),
    ]))->toThrow(DuplicatePermissionException::class, '"orders.view" of panel "admin" is defined differently by source "a" and source "b"');
});

it('V08: names the plugin when a plugin source repeats a name of the provider', function (): void {
    expect(fn () => collisionPanel(
        [new StaticSource('app', [StaticSource::grants('posts.edit', 'Edit')])],
        ['acme/blog' => [new StaticSource('blog', [StaticSource::grants('posts.edit', 'Edit posts')])]],
    ))->toThrow(DuplicatePermissionException::class, 'source "app" and source "blog" (plugin:acme/blog)');
});

it('V08: keeps the names of a plugin as declared, next to the names of the provider', function (): void {
    $catalog = collisionPanel(
        [StaticSource::names('app', 'posts.edit')],
        ['acme/blog' => [StaticSource::names('blog', 'blog.posts.edit')]],
    )->catalog('admin');

    expect(array_keys($catalog->all()))->toBe(['posts.edit', 'blog.posts.edit'])
        ->and($catalog->snapshot()['permissions'][1]['origin'])->toBe('plugin:acme/blog');
});

it('rejects one enum case that names two permissions', function (): void {
    expect(fn () => collisionPanel([new StaticSource('a', [
        new PermissionDefinition('clients.view', PermissionAuthority::Grants, case: ClientPermission::View),
        new PermissionDefinition('clients.show', PermissionAuthority::Grants, case: ClientPermission::View),
    ])]))->toThrow(DuplicatePermissionException::class, ClientPermission::class.'::View of panel "admin" names "clients.view"');
});

it('V119: one name has one authority mode across sources', function (): void {
    expect(fn () => collisionPanel([
        new StaticSource('a', [new PermissionDefinition('clients.own', PermissionAuthority::Policy)], policies: [PolicyBinding::for('clients.own', ClientPolicy::class)]),
        new StaticSource('b', [new PermissionDefinition('clients.own', PermissionAuthority::Grants)]),
    ]))->toThrow(DuplicatePermissionException::class, '"clients.own"');
});

it('V119: a policy-only permission needs a binding and is never listed by a role', function (): void {
    expect(fn () => collisionPanel([new StaticSource('crm', ClientPermission::definitions())]))
        ->toThrow(InvalidPolicyStructureException::class, 'clients.view_own_profile')
        ->and(fn () => collisionPanel([new StaticSource('crm', ClientPermission::definitions(), [new ProfileViewerRole], [
            PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class),
        ])]))->toThrow(DefinitionException::class, 'never assigned');
});

it('V119: a dynamic permission is always decided by grants', function (): void {
    $catalog = collisionPanel([StaticSource::names('a', 'orders.view')])->catalog('admin');

    expect(fn () => $catalog->withDynamic([new PermissionDefinition('reports.own', PermissionAuthority::Policy)]))
        ->toThrow(InvalidSourceContributionException::class, 'always decided by grants');
});

it('rejects a source named by a name no factory registers', function (): void {
    expect(fn () => collisionPanel(['ldap']))->toThrow(UnknownSourceException::class, 'names the source "ldap", which is not registered');
});
