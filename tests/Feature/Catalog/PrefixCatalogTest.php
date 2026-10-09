<?php

declare(strict_types=1);

use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Support\Facades\DB;

it('V81: rejects a panel prefix that is the first segment of a permission name of another panel', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('app', 'orders.view')]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('orders'),
    ]))->toThrow(
        PrefixConflictException::class,
        'The permission prefix "orders" of panel "cabinet" is the first segment of the permission "orders.view" of panel "admin"',
    );
});

it('V81: rejects a prefix that a name contributed by a plugin starts with, on the same panel too', function (): void {
    $plugin = ProbePlugin::make('acme/blog', register: static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
        StaticSource::names('blog', 'blog.posts.edit'),
    ]));

    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('blog')->plugins([$plugin]),
    ]))->toThrow(PrefixConflictException::class, 'prefix "blog" of panel "admin" is the first segment of the permission "blog.posts.edit"');
});

it('V81: accepts names whose first segment is no prefix', function (): void {
    $registry = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('app', 'orders.view')]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false)->permissions([StaticSource::names('app', 'orders.view')]),
    ])[2];

    expect($registry->catalog('admin')->has('orders.view'))->toBeTrue()
        ->and($registry->catalog('cabinet')->has('orders.view'))->toBeTrue();
});

it('gives an unqualified ability to the candidate panel only when its catalog has it, without queries', function (): void {
    [$resolver] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)
            ->permissions([StaticSource::names('app', 'orders.view')]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Seller::class)
            ->permissions([StaticSource::names('app', 'invoices.view')]),
    ]);

    DB::enableQueryLog();

    expect($resolver->owner('orders.view', new User)?->id())->toBe('admin')
        ->and($resolver->owner('posts.view', new User))->toBeNull()
        ->and($resolver->owner('invoices.view', new User))->toBeNull()
        ->and($resolver->owner('invoices.view', new Seller)?->id())->toBe('cabinet')
        ->and($resolver->owner('admin.posts.view', new User)?->id())->toBe('admin')
        ->and($resolver->owner('orders.view'))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});
