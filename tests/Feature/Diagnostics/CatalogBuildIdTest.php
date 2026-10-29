<?php

declare(strict_types=1);

use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Support\Facades\Artisan;

/*
 * `catalog.build_id` in production: without `catalog.build_id` a deployment that changes code outside the panel
 * providers keeps the id of the previous one. That is an error when a catalog cache file or a persistent panel cache
 * store can serve the stale build, a warning otherwise. The key and the finding id never change.
 */

afterEach(function (): void {
    CrmWorld::resetRuntime();
});

function buildIdPanel(bool $store = false): string
{
    $path = sys_get_temp_dir().'/azguard-build-id-'.bin2hex(random_bytes(4)).'.php';
    config(['azguard.catalog.cache_path' => $path]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static function (PanelBuilder $panel) use ($store): void {
        $panel->for(User::class)->permissions([OrderPermission::class, DatabaseSource::make()]);

        if ($store) {
            $panel->cache(store: 'array', ttl: 60);
        }
    }]);

    return $path;
}

/** @return list<string> */
function buildIdFindings(bool $production = true): array
{
    return DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: $production), 'catalog.build_id'));
}

it('warns in production without a build id when nothing caches', function (): void {
    buildIdPanel();

    expect(buildIdFindings())->toBe(['core catalog.build_id warning']);
});

it('is an error in production without a build id when the catalog cache file exists', function (): void {
    $path = buildIdPanel();

    try {
        Artisan::call('azguard:catalog:cache');
        DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([OrderPermission::class, DatabaseSource::make()])]);

        expect(buildIdFindings())->toBe(['core catalog.build_id error']);
    } finally {
        @unlink($path);
    }
});

it('is an error in production without a build id when a panel uses a persistent cache store', function (): void {
    buildIdPanel(store: true);

    expect(buildIdFindings())->toBe(['core catalog.build_id error']);
});

it('reports nothing when the build id is set', function (): void {
    buildIdPanel(store: true);
    config(['azguard.catalog.build_id' => 'release-42']);

    expect(buildIdFindings())->toBe([]);
});

it('reports nothing outside production', function (): void {
    buildIdPanel(store: true);

    expect(buildIdFindings(production: false))->toBe([]);
});

it('names the reason and the remedy in the error and fails the doctor', function (): void {
    buildIdPanel(store: true);

    $exit = Artisan::call('azguard:doctor', ['--json' => true, '--production' => true]);
    $findings = array_values(array_filter(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), static fn (array $finding): bool => $finding['key'] === 'catalog.build_id'));

    expect($exit)->toBe(1)
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('error')
        ->and($findings[0]['message'])->toContain('persistent')->toContain('commit SHA')->toContain('AZGUARD_BUILD_ID');
});
