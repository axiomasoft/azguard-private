<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use Illuminate\Support\Facades\Artisan;

/*
 * V84: the freshness of the catalog cache that `php artisan about` reports is the answer the doctor gives for
 * `catalog.cached` in production.
 */

afterEach(function (): void {
    CrmWorld::resetRuntime();
});

it('V84 reports the catalog cache state the doctor warns about: missing, current, stale', function (): void {
    $path = sys_get_temp_dir().'/azguard-about-'.bin2hex(random_bytes(4)).'.php';
    config(['azguard.catalog.cache_path' => $path, 'azguard.catalog.build_id' => 'release-42']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    DoctorWorld::migrate();
    DoctorWorld::notes();

    /** @return array{0: string, 1: list<string>} the state `about` prints and the doctor findings of catalog.cached */
    $both = static fn (): array => [
        aboutCache(),
        DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: true), 'catalog.cached')),
    ];

    try {
        expect($both())->toBe(['missing', ['core catalog.cached warning']]);

        Artisan::call('azguard:catalog:cache');
        DoctorWorld::notes();
        expect($both())->toBe(['current', []]);

        DoctorWorld::notes(static fn (PanelBuilder $panel) => $panel->permissions([OrderPermission::class]));
        expect($both())->toBe(['stale (notes)', ['panel:notes catalog.cached warning']]);
    } finally {
        @unlink($path);
    }
});

function aboutCache(): string
{
    expect(Artisan::call('about', ['--json' => true, '--only' => 'azguard']))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['az_guard']['catalog_cache'];
}
