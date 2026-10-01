<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;

// A custom builder an integrator registers via the public API. It contributes
// one definition to the 'test' panel catalog.
class StubCatalogBuilder implements PermissionCatalogBuilder
{
    public function build(string $panelId): array
    {
        return [new SimplePermissionDefinition(key: 'test.custom.report', panelId: 'test')];
    }

    public function supports(string $panelId): bool
    {
        return $panelId === 'test';
    }
}

// F7: AzGuard::registerCatalogBuilder() plugs a custom builder into catalog
// assembly through the same public, tagged surface GrantSource already has.
it('registers a custom catalog builder via the public API and it joins the catalog', function () {
    expect(app(PermissionCatalog::class)->has('test', 'test.custom.report'))->toBeFalse();

    AzGuard::registerCatalogBuilder(StubCatalogBuilder::class);
    // Rebuild the boot-time catalog singleton so it re-collects the freshly tagged builder.
    app()->forgetInstance(PermissionCatalog::class);

    expect(app(PermissionCatalog::class)->has('test', 'test.custom.report'))->toBeTrue();
});
