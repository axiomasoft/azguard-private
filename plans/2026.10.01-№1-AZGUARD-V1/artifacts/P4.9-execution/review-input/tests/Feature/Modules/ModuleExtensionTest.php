<?php

declare(strict_types=1);

use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Modules\Blog\BlogServiceProvider;
use AzGuard\Tests\Fixtures\Modules\Shop\ShopServiceProvider;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel);
});

it('V55: puts each module permission and role on the configured panel under the names the modules chose', function (): void {
    $this->bootPanels(
        ['providers' => [AdminPanel::class]],
        [BlogServiceProvider::class, ShopServiceProvider::class],
    );

    $catalog = app(PanelRegistry::class)->catalog('admin');
    $permissions = $catalog->snapshot()['permissions'];

    expect(array_keys($catalog->all()))->toBe(['blog.posts.edit', 'shop.posts.edit'])
        ->and(array_keys($catalog->roles()))->toBe(['blog-editor', 'shop-editor'])
        ->and($permissions[0]['origin'])->toBe('plugin:blog/access')
        ->and($permissions[1]['origin'])->toBe('plugin:shop/access')
        ->and(app(PanelRegistry::class)->get('admin')->pluginIds())->toBe(['blog/access', 'shop/access']);
});

it('V55: rejects two modules that declare one permission name and names both plugins', function (): void {
    $boot = fn () => $this->bootPanels(
        ['providers' => [AdminPanel::class]],
        [BlogServiceProvider::class, ShopServiceProvider::class],
        ['shop.permission' => 'blog.posts.edit'],
    );

    try {
        $boot();
        $this->fail('two modules with one permission name must fail while the application boots');
    } catch (DuplicatePermissionException $exception) {
        expect($exception->code())->toBe('duplicate_permission')
            ->and($exception->getMessage())->toContain('plugin:blog/access')
            ->and($exception->getMessage())->toContain('plugin:shop/access')
            ->and(app(PanelRegistry::class)->isFrozen())->toBeFalse();
    }
});

it('V55: reports an unknown panel from the module configuration when the panels are compiled', function (): void {
    $boot = fn () => $this->bootPanels(
        ['providers' => [AdminPanel::class]],
        [BlogServiceProvider::class],
        ['blog.azguard_panel' => 'nope'],
    );

    try {
        $boot();
        $this->fail('an unknown panel id must fail while the application boots');
    } catch (UnknownPanelException $exception) {
        expect($exception->code())->toBe('unknown_panel')
            ->and($exception->getMessage())->toContain('"nope"');
    }
});
