<?php

declare(strict_types=1);

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    FixturePanel::reset();
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-resolver-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'resolver-build';
});

afterEach(function (): void {
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
});

it('R2: resolves a catalog enum from a custom source', function (): void {
    [$resolver, , $registry] = PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $p) => $p->permissions([new StaticSource('app', [
            new PermissionDefinition('clients.update', PermissionAuthority::Grants, case: ClientPermission::Update),
        ])]),
    ]);
    expect($registry->catalog('admin')->keyOf(ClientPermission::Update)->full())->toBe('admin:clients.update');
    expect($resolver->resolve(permission: ClientPermission::Update)['panel']->id())->toBe('admin');
});

it('indexes custom catalog cases with sole, shared and conflicting panel signals on live and cached boots', function (bool $shared, bool $cached): void {
    $attach = static fn (PanelBuilder $p): PanelBuilder => $p->permissions([new StaticSource('app', [
        new PermissionDefinition('clients.update', PermissionAuthority::Grants, case: ClientPermission::Update),
    ])]);
    AdminPanel::describe($attach);
    CabinetPanel::describe($shared ? $attach : static fn (PanelBuilder $p): PanelBuilder => $p);
    $this->bootCatalogPanels([AdminPanel::class, CabinetPanel::class]);
    $live = app(PanelRegistry::class);
    $snapshot = $live->catalog('admin')->snapshot();
    $fingerprint = $live->fingerprint('admin');

    if ($cached) {
        $this->artisan('azguard:catalog:cache')->assertSuccessful();
        $file = require self::$catalogCachePath;
        expect($file['panels']['admin']['catalog'])->toBe($snapshot);
        $this->bootCatalogPanels([AdminPanel::class, CabinetPanel::class]);
        expect(StaticSource::$reads)->toBe([])
            ->and(app(PanelRegistry::class)->catalog('admin'))->not->toBe($live->catalog('admin'));
    } else {
        expect(StaticSource::$reads)->toBe(['app' => $shared ? 2 : 1]);
    }

    $registry = app(PanelRegistry::class);
    $resolver = app(PanelResolver::class);
    expect($registry->catalog('admin')->snapshot())->toBe($snapshot)
        ->and($registry->fingerprint('admin'))->toBe($fingerprint)
        ->and(array_map(static fn ($panel): string => $panel->id(), $registry->forEnum(ClientPermission::class)))
        ->toBe($shared ? ['admin', 'cabinet'] : ['admin'])
        ->and($resolver->resolve(permission: ClientPermission::Update, panel: 'admin')['key']->full())->toBe('admin:clients.update');

    if ($shared) {
        expect(fn () => $resolver->resolve(permission: ClientPermission::Update))->toThrow(AmbiguousPanelException::class)
            ->and($resolver->resolve(permission: ClientPermission::Update, panel: 'cabinet')['key']->full())->toBe('cabinet:clients.update');
    } else {
        expect($resolver->resolve(permission: ClientPermission::Update)['panel']->id())->toBe('admin')
            ->and(fn () => $resolver->resolve(permission: ClientPermission::Update, panel: 'cabinet'))
            ->toThrow(ConflictingPanelException::class, 'Explicit panel signals disagree');
    }
})->with([
    'sole live' => [false, false],
    'sole cached' => [false, true],
    'shared live' => [true, false],
    'shared cached' => [true, true],
]);
