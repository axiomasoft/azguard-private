<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Permissions\AttributedClientPolicy;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    FixturePanel::reset();
    StaticSource::$reads = [];
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-catalog-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'build-1';

    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->scopes(AssignmentScopePolicy::inherit(ProjectScope::class))->permissions([
        StaticSource::names('orders', 'orders.view', 'orders.update'),
        new StaticSource('crm', ClientPermission::definitions(), [new SellerRole], [PolicyBinding::for(ClientPermission::ViewOwnProfile, AttributedClientPolicy::class)]),
    ]));
});

afterEach(function (): void {
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
});

/**
 * @return array{snapshot: array<mixed>, fingerprint: string, reads: array<string, int>}
 */
function bootCachedAdmin(object $test): array
{
    $test->bootCatalogPanels([AdminPanel::class]);
    $registry = app(PanelRegistry::class);

    return ['snapshot' => $registry->catalog('admin')->snapshot(), 'fingerprint' => $registry->fingerprint('admin'), 'reads' => StaticSource::$reads];
}

/**
 * @param  array<mixed>  $value
 */
function onlyScalars(array $value): bool
{
    foreach ($value as $item) {
        if (is_array($item) ? ! onlyScalars($item) : ! (is_scalar($item) || $item === null)) {
            return false;
        }
    }

    return true;
}

it('writes the static catalogs as a PHP array of scalars and boots from it with an equal catalog', function (): void {
    $live = bootCachedAdmin($this);

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require self::$catalogCachePath;

    expect($live['reads'])->toBe(['orders' => 1, 'crm' => 1])
        ->and(app(CatalogCache::class)->path())->toBe(self::$catalogCachePath)
        ->and(onlyScalars($file))->toBeTrue()
        ->and($file['build_id'])->toBe('build-1')
        ->and($file['panels']['admin']['catalog'])->toBe($live['snapshot'])
        ->and($file['panels']['admin']['catalog']['roles']['seller']['class'])->toBe(SellerRole::class);

    $cached = bootCachedAdmin($this);

    expect($cached['reads'])->toBe([])
        ->and($cached['snapshot'])->toBe($live['snapshot'])
        ->and($cached['fingerprint'])->toBe($live['fingerprint'])
        ->and(array_keys(app(PanelRegistry::class)->catalog('admin')->all()))->toBe(array_column($live['snapshot']['permissions'], 'local'))
        ->and(app(PanelRegistry::class)->catalog('admin')->keyOf(ClientPermission::Update)->full())->toBe('admin:clients.update');
});

it('ignores the file written by another build', function (): void {
    bootCachedAdmin($this);
    $this->artisan('azguard:catalog:cache')->assertSuccessful();

    self::$catalogBuildId = 'build-2';

    expect(bootCachedAdmin($this)['reads'])->toBe(['orders' => 1, 'crm' => 1]);
});

it('ignores the entry of a panel whose recipe changed', function (): void {
    bootCachedAdmin($this);
    $this->artisan('azguard:catalog:cache')->assertSuccessful();

    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([StaticSource::names('orders', 'orders.view')]));
    $changed = bootCachedAdmin($this);

    expect($changed['reads'])->toBe(['orders' => 1])
        ->and(array_column($changed['snapshot']['permissions'], 'local'))->toBe(['orders.view']);
});

it('removes the file with azguard:catalog:clear and builds from the sources again', function (): void {
    bootCachedAdmin($this);
    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $this->artisan('azguard:catalog:clear')->assertSuccessful();

    expect(file_exists(self::$catalogCachePath))->toBeFalse()
        ->and(bootCachedAdmin($this)['reads'])->toBe(['orders' => 1, 'crm' => 1]);

    $this->artisan('azguard:catalog:clear')->assertSuccessful();
});

it('builds from the sources when the file holds no catalog', function (string $content): void {
    mkdir(dirname(self::$catalogCachePath), 0o755, true);
    file_put_contents(self::$catalogCachePath, $content);

    expect(bootCachedAdmin($this)['reads'])->toBe(['orders' => 1, 'crm' => 1]);
})->with([
    'not an array' => ['<?php return "stale";'],
    'another version' => ['<?php return ["version" => 0, "build_id" => "build-1", "panels" => []];'],
    'a broken entry' => ['<?php return ["version" => 1, "build_id" => "build-1", "panels" => ["admin" => ["fingerprint" => "x", "catalog" => []]]];'],
]);

it('preserves the first owner of equal permission and role contributions within a plugin across a real cache round trip', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ProbePlugin::make('one/access', register: static fn (PanelBuilder $p): PanelBuilder => $p->permissions([
            new StaticSource('one-access', [StaticSource::grants('clients.view')], [new ManagerRole, new ManagerRole]),
            new StaticSource('one-more', [StaticSource::grants('clients.view')], [new ManagerRole]),
        ])),
    ]));
    $live = bootCachedAdmin($this);
    expect($live['snapshot']['permissions'][0]['source'])->toBe('one-access')
        ->and($live['snapshot']['permissions'][0]['origin'])->toBe('plugin:one/access')
        ->and($live['snapshot']['roles']['manager']['source'])->toBe('one-access')
        ->and($live['snapshot']['roles']['manager']['origin'])->toBe('plugin:one/access');

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $cached = bootCachedAdmin($this);
    expect($cached['reads'])->toBe([])
        ->and($cached['snapshot'])->toBe($live['snapshot'])
        ->and($cached['fingerprint'])->toBe($live['fingerprint']);
});
