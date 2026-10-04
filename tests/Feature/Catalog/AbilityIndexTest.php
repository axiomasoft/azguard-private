<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\PoliciesGate\IndexedPermission;

it('indexes model and the last permission segment with snake case normalization and explicit ambiguity', function (): void {
    [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->resourcePrefix(false)->permissions([IndexedPermission::class])]);
    $catalog = $registry->catalog('admin');

    expect($catalog->permissionForAbility(GateRecord::class, 'viewAny'))->toBe('inventory.view_any')
        ->and($catalog->permissionForAbility(GateRecord::class, 'view_any'))->toBe('inventory.view_any')
        ->and($catalog->permissionForAbility(GateRecord::class, 'update'))->toBe('inventory.update')
        ->and($catalog->permissionForAbility(User::class, 'update'))->toBeNull()
        ->and($catalog->permissionForAbility(GateRecord::class, 'missing'))->toBeNull()
        ->and($catalog->abilityIsAmbiguous(GateRecord::class, 'missing'))->toBeFalse()
        ->and($catalog->permissionForAbility(GateRecord::class, 'read'))->toBeNull()
        ->and($catalog->abilityIsAmbiguous(GateRecord::class, 'read'))->toBeTrue();
});

it('retains successful, absent and ambiguous ability lookup after a real cache file round trip', function (): void {
    [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->resourcePrefix(false)->permissions([IndexedPermission::class])]);
    $live = $registry->catalog('admin');
    $cache = new CatalogCache(sys_get_temp_dir().'/azguard-ability-'.bin2hex(random_bytes(8)).'.php');

    try {
        $cache->write('ability-build', ['admin' => ['fingerprint' => 'recipe', 'catalog' => $live->snapshot()]]);
        $entry = CatalogCache::entry($cache->read(), 'ability-build', 'admin', 'recipe');
        expect($entry)->not->toBeNull();
        $restored = PanelCatalog::fromSnapshot($entry);

        expect($restored->snapshot())->toBe($live->snapshot())
            ->and($restored->permissionForAbility(GateRecord::class, 'viewAny'))->toBe('inventory.view_any')
            ->and($restored->permissionForAbility(GateRecord::class, 'missing'))->toBeNull()
            ->and($restored->abilityIsAmbiguous(GateRecord::class, 'missing'))->toBeFalse()
            ->and($restored->permissionForAbility(GateRecord::class, 'read'))->toBeNull()
            ->and($restored->abilityIsAmbiguous(GateRecord::class, 'read'))->toBeTrue();
    } finally {
        $cache->clear();
    }
});
