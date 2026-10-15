<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Support\ServiceProvider;

/*
 * V84 (optimize part): `optimize` builds the catalog cache and `optimize:clear` removes it, through `optimizes()` of
 * the framework.
 */

uses(BootsWithCatalogCache::class);

/** Laravel's own tasks are not the subject: only what the package registered runs. */
const FRAMEWORK_TASKS = 'config,events,routes,views,cache,compiled';

/**
 * `--except` list that leaves the AzGuard task only. `ServiceProvider::$optimizeCommands` is static, so the tasks of
 * every provider an earlier test in the process booted (blade-icons through Filament) stay registered in this
 * application, which has no such command.
 *
 * @param  array<string, string>  $registered
 */
function exceptOtherTasks(array $registered): string
{
    return implode(',', [FRAMEWORK_TASKS, ...array_diff(array_keys($registered), ['azguard'])]);
}

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

    $this->artisan('optimize', ['--except' => exceptOtherTasks(ServiceProvider::$optimizeCommands)])->assertSuccessful();
    expect(is_file(self::$catalogCachePath))->toBeTrue()
        ->and(require self::$catalogCachePath)->toHaveKeys(['build_id', 'panels']);

    $this->artisan('optimize:clear', ['--except' => exceptOtherTasks(ServiceProvider::$optimizeClearCommands)])->assertSuccessful();
    expect(is_file(self::$catalogCachePath))->toBeFalse();
});
