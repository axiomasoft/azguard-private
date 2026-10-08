<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Permissions\Posts\PostPermission;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Roles\BlogEditorRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

it('rejects the same explicit role class contributed by independent plugins', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $panel) => $panel
            ->permissions([StaticSource::names('app', 'clients.view')])
            ->plugins([
                ProbePlugin::make('one/access', register: fn (PanelBuilder $panel) => $panel->roles([ManagerRole::class])),
                ProbePlugin::make('two/access', register: fn (PanelBuilder $panel) => $panel->roles([ManagerRole::class])),
            ]),
    ]))->toThrow(DuplicateRoleException::class, 'plugin:one/access and plugin:two/access');
});

it('rejects the same explicit role class contributed by a provider and a plugin', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $panel) => $panel
            ->permissions([StaticSource::names('app', 'clients.view')])->roles([ManagerRole::class])
            ->plugins([ProbePlugin::make('one/access', register: fn (PanelBuilder $panel) => $panel->roles([ManagerRole::class]))]),
    ]))->toThrow(DuplicateRoleException::class, 'provider and plugin:one/access');
});

it('keeps repeated explicit roles of the same plugin idempotent', function (): void {
    [,, $registry] = PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $panel) => $panel
            ->permissions([StaticSource::names('app', 'clients.view')])
            ->plugins([ProbePlugin::make('one/access', register: fn (PanelBuilder $panel) => $panel
                ->roles([ManagerRole::class])->roles([ManagerRole::class]))]),
    ]);

    expect(array_keys($registry->catalog('admin')->roles()))->toBe(['manager']);
});

it('preserves discovered plugin role ownership through catalog and discovery cache restoration', function (): void {
    $root = dirname((new ReflectionClass(BlogEditorRole::class))->getFileName(), 2);
    $path = sys_get_temp_dir().'/azguard-role-origins-'.bin2hex(random_bytes(8)).'.php';
    $cache = new CatalogCache($path);
    AdminPanel::describe(fn (PanelBuilder $panel) => $panel
        ->permissions([FolderSource::make()->folders(permissions: 'NoPermissions', policies: 'NoPolicies'), PostPermission::class])
        ->plugins([ProbePlugin::make('one/access', register: fn (PanelBuilder $panel) => $panel
            ->discover($root, 'AzGuard\\Tests\\Fixtures\\Modules\\Blog\\Guards')->roles([BlogEditorRole::class]))]));

    try {
        foreach ([false, true] as $cached) {
            $registry = new PanelRegistry(app(), cache: fn () => $cache);
            $registry->register(AdminPanel::class);
            $registry->freeze();

            expect($registry->catalog('admin')->roles()['blog-editor']['origin'])->toBe('plugin:one/access')
                ->and($registry->discovery('admin')['role_origins'][BlogEditorRole::class])->toBe(['plugin:one/access'])
                ->and($registry->isCached('admin'))->toBe($cached);
            $cache->write($registry->buildId(), $registry->snapshot());
        }
    } finally {
        @unlink($path);
    }
});

it('merges the same role from folder and custom sources within one plugin origin', function (): void {
    [,, $registry] = PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $panel) => $panel->plugins([
            ProbePlugin::make('one/access', register: fn (PanelBuilder $panel) => $panel
                ->roles([ManagerRole::class])
                ->permissions([new StaticSource('app', [StaticSource::grants('clients.view')], [new ManagerRole])])),
        ]),
    ]);

    expect($registry->catalog('admin')->roles()['manager']['origin'])->toBe('plugin:one/access');
});
