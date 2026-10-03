<?php

declare(strict_types=1);

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Modules\Blog\BlogGuardPanelProvider;
use AzGuard\Tests\Fixtures\Modules\Blog\BlogModuleProvider;
use AzGuard\Tests\Fixtures\Modules\Blog\BlogPermission;
use AzGuard\Tests\Fixtures\Modules\Blog\DetachedPermission;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
    AdminPanel::describe(
        static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([BlogPermission::class]),
    );
});

it('shows a panel the module registered through the facade', function (): void {
    $this->bootPanels(['providers' => [AdminPanel::class]], [BlogModuleProvider::class]);

    expect(array_keys(AzGuard::panels()))->toBe(['blog', 'admin'])
        ->and(AzGuard::panels()['blog']->id())->toBe(BlogGuardPanelProvider::getId())
        ->and(app(PanelRegistry::class)->forEnum(BlogPermission::class))->toHaveCount(2);
});

it('V07: rejects a module enum attached to no panel when it is resolved', function (): void {
    $this->bootPanels(['providers' => [AdminPanel::class]], [BlogModuleProvider::class]);

    expect(fn () => app(PanelResolver::class)->resolve(permission: DetachedPermission::Publish))
        ->toThrow(UnknownPermissionException::class, 'is not attached to any panel');
});

it('V07: requires an explicit panel for a module enum attached to the admin panel and the module panel', function (): void {
    $this->bootPanels(['providers' => [AdminPanel::class]], [BlogModuleProvider::class]);

    $resolver = app(PanelResolver::class);
    app(CurrentPanel::class)->set($resolver->resolve(panel: 'admin')['panel']);

    expect(fn () => $resolver->resolve(permission: BlogPermission::Edit))
        ->toThrow(AmbiguousPanelException::class, '"blog", "admin"')
        ->and($resolver->resolve(permission: BlogPermission::Edit, panel: 'blog')['panel']->id())->toBe('blog')
        ->and($resolver->resolve(permission: BlogPermission::Edit, panel: 'admin')['panel']->id())->toBe('admin')
        ->and($resolver->resolve(permission: BlogPermission::Edit, panel: 'admin')['key']?->full())->toBe('admin:blog.posts.edit');
});
