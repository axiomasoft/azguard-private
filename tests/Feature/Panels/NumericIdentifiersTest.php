<?php

declare(strict_types=1);

use AzGuard\Catalog\CacheState;
use AzGuard\Catalog\CatalogCache;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\AnyIdPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;

beforeEach(function (): void {
    FixturePanel::reset();
});

it('compiles and caches numeric panel identifiers without changing their identity', function (string $id): void {
    AnyIdPanel::$id = $id;
    AnyIdPanel::describe(fn (PanelBuilder $panel) => $panel->permissions([OrderPermission::class]));
    $path = sys_get_temp_dir().'/azguard-numeric-panel-'.bin2hex(random_bytes(8)).'.php';
    $cache = new CatalogCache($path);

    try {
        $registry = new PanelRegistry(app(), cache: fn () => $cache);
        $registry->register(AnyIdPanel::class);
        $registry->configure($id, fn (PanelBuilder $panel) => $panel->label('Numeric'));
        $registry->freeze();
        $resolver = new PanelResolver($registry, new CurrentPanel);

        expect($registry->get($id)->id())->toBe($id)
            ->and($registry->get($id)->label())->toBe('Numeric')
            ->and($resolver->resolve(permission: $id.':orders.view')['key']->full())->toBe($id.':orders.view')
            ->and($resolver->resolve(permission: $id.'.orders.view')['key']->full())->toBe($id.':orders.view');

        $snapshot = $registry->snapshot();
        $cache->write($registry->buildId(), $snapshot);

        expect($registry->isCached($id))->toBeTrue()
            ->and($registry->cacheState())->toBe(CacheState::Current);

        $cached = new PanelRegistry(app(), cache: fn () => $cache);
        $cached->register(AnyIdPanel::class);
        $cached->configure($id, fn (PanelBuilder $panel) => $panel->label('Numeric'));
        $cached->freeze();

        expect($cached->catalog($id)->snapshot())->toBe($snapshot[$id]['catalog'])
            ->and($cached->fingerprint($id))->toBe($registry->fingerprint($id));
    } finally {
        @unlink($path);
    }
})->with(['zero' => '0', 'integer-shaped' => '7', 'leading zero' => '007']);

it('accepts a numeric permission prefix on an alphabetic panel', function (): void {
    AdminPanel::describe(fn (PanelBuilder $panel) => $panel->resourcePrefix('7')->permissions([OrderPermission::class]));
    $registry = new PanelRegistry(app());
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect((new PanelResolver($registry, new CurrentPanel))->resolve(permission: '7.orders.view')['key']->full())
        ->toBe('admin:orders.view');
});

it('reports an unknown numeric configure target as a domain exception', function (): void {
    $registry = new PanelRegistry(app());
    $registry->configure('7', fn () => null);

    expect(fn () => $registry->freeze())->toThrow(UnknownPanelException::class, '"7"');
});
