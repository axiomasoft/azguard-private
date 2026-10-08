<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Support\ServiceProvider;

/*
 * V84 (optimize part): `optimize` builds the catalog cache and `optimize:clear` removes it, through `optimizes()` of
 * the framework. Laravel before 11.27.1 has no `optimizes()`; the provider then registers nothing and does not fail.
 */

uses(BootsWithCatalogCache::class);

/** Laravel's own tasks are not the subject: only what the package registered runs. */
const FRAMEWORK_TASKS = 'config,events,routes,views,cache,compiled';

beforeEach(function (): void {
    FixturePanel::reset();
    StaticSource::$reads = [];
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-optimize-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'build-1';
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('orders', 'orders.view')]));
});

afterEach(function (): void {
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
});

it('V84 builds the catalog cache with optimize and removes it with optimize:clear', function (): void {
    $this->bootCatalogPanels([AdminPanel::class]);

    expect(ServiceProvider::$optimizeCommands['azguard'])->toBe('azguard:catalog:cache')
        ->and(ServiceProvider::$optimizeClearCommands['azguard'])->toBe('azguard:catalog:clear')
        ->and(is_file(self::$catalogCachePath))->toBeFalse();

    $this->artisan('optimize', ['--except' => FRAMEWORK_TASKS])->assertSuccessful();
    expect(is_file(self::$catalogCachePath))->toBeTrue()
        ->and(require self::$catalogCachePath)->toHaveKeys(['build_id', 'panels']);

    $this->artisan('optimize:clear', ['--except' => FRAMEWORK_TASKS])->assertSuccessful();
    expect(is_file(self::$catalogCachePath))->toBeFalse();
});

it('V84 registers nothing, and does not fail, on a framework without optimizes()', function (): void {
    $this->bootCatalogPanels([AdminPanel::class]);
    $provider = new AzGuardServiceProvider($this->app);
    $register = new ReflectionMethod($provider, 'registerOptimizations');
    [$optimize, $clear] = [ServiceProvider::$optimizeCommands, ServiceProvider::$optimizeClearCommands];
    ServiceProvider::$optimizeCommands = ServiceProvider::$optimizeClearCommands = [];

    try {
        $register->invoke($provider, false);
        expect(ServiceProvider::$optimizeCommands)->toBe([])
            ->and(ServiceProvider::$optimizeClearCommands)->toBe([]);

        $register->invoke($provider, true);
        expect(ServiceProvider::$optimizeCommands)->toBe(['azguard' => 'azguard:catalog:cache']);
    } finally {
        [ServiceProvider::$optimizeCommands, ServiceProvider::$optimizeClearCommands] = [$optimize, $clear];
    }
});
