<?php

declare(strict_types=1);

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Permissions\AttributedClientPolicy;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Permissions\ClientPolicy;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

uses(BootsWithCatalogCache::class);

final class ReviewPolicy
{
    #[Decides('orders.view')]
    public function arbitraryName(): bool
    {
        return false;
    }

    public function unrelated(): bool
    {
        return true;
    }
}

final class AmbiguousBindingPolicy
{
    #[Decides('orders.view')]
    public function first(): bool
    {
        return false;
    }

    #[Decides('orders.view')]
    public function second(): bool
    {
        return false;
    }
}

final class NonpublicBindingPolicy
{
    #[Decides('orders.view')]
    protected function hidden(): bool
    {
        return false;
    }
}

final class StaticBindingPolicy
{
    #[Decides('orders.view')]
    public static function decides(): bool
    {
        return false;
    }
}

final class WrongAttributeBindingPolicy
{
    #[Decides('orders.update')]
    public function view(): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    FixturePanel::reset();
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-binding-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'binding-build';
});

afterEach(function (): void {
    if (is_file(self::$catalogCachePath)) {
        unlink(self::$catalogCachePath);
    }

    if (is_dir(dirname(self::$catalogCachePath))) {
        rmdir(dirname(self::$catalogCachePath));
    }
});

it('R1: keeps the attributed method', function (): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $p) => $p
            ->permissions([StaticSource::names('app', 'orders.view')])
            ->policies([PolicyBinding::for('orders.view', ReviewPolicy::class)]),
    ]);
    expect($registry->catalog('admin')->bindingMethod('orders.view'))->toBe('arbitraryName');
});

it('R1: rejects an unrelated method', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $p) => $p
            ->permissions([StaticSource::names('app', 'orders.view')])
            ->policies([PolicyBinding::for('orders.view', ReviewPolicy::class, 'unrelated')]),
    ]))->toThrow(DefinitionException::class);
});

it('R1: rejects a custom source binding without Decides', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $p) => $p->permissions([
            new StaticSource('app', [new PermissionDefinition('orders.view', PermissionAuthority::Policy)], [], [
                PolicyBinding::for('orders.view', ClientPolicy::class),
            ]),
        ]),
    ]))->toThrow(DefinitionException::class);
});

it('accepts the explicit attributed method from folder and custom sources', function (bool $custom): void {
    $binding = PolicyBinding::for('orders.view', ReviewPolicy::class, 'arbitraryName');
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $custom
            ? $panel->permissions([new StaticSource('app', [StaticSource::grants('orders.view')], policies: [$binding])])
            : $panel->permissions([StaticSource::names('app', 'orders.view')])->policies([$binding]),
    ]);

    expect($registry->catalog('admin')->bindingMethod('orders.view'))->toBe('arbitraryName');
})->with(['folder' => false, 'custom' => true]);

it('normalizes a custom source binding by enum case and local name', function (bool $byCase): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
            new StaticSource('app', ClientPermission::definitions(), policies: [
                PolicyBinding::for($byCase ? ClientPermission::ViewOwnProfile : 'clients.view_own_profile', AttributedClientPolicy::class),
            ]),
        ]),
    ]);

    expect($registry->catalog('admin')->bindingMethod('clients.view_own_profile'))->toBe('ownProfile')
        ->and($registry->catalog('admin')->snapshot()['binding_methods'])->toBe(['clients.view_own_profile' => 'ownProfile']);
})->with(['case' => true, 'name' => false]);

it('rejects an invalid attributed method from every binding source', function (string $policy, ?string $method, bool $custom): void {
    $binding = PolicyBinding::for('orders.view', $policy, $method);

    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $custom
            ? $panel->permissions([new StaticSource('app', [StaticSource::grants('orders.view')], policies: [$binding])])
            : $panel->permissions([StaticSource::names('app', 'orders.view')])->policies([$binding]),
    ]))->toThrow(DefinitionException::class);
})->with([
    'missing' => [ClientPolicy::class, null],
    'wrong permission' => [WrongAttributeBindingPolicy::class, null],
    'ambiguous' => [AmbiguousBindingPolicy::class, null],
    'ambiguous explicit' => [AmbiguousBindingPolicy::class, 'first'],
    'nonpublic' => [NonpublicBindingPolicy::class, 'hidden'],
    'static' => [StaticBindingPolicy::class, 'decides'],
    'unrelated' => [ReviewPolicy::class, 'unrelated'],
    'nonexistent' => [ReviewPolicy::class, 'missing'],
])->with(['folder' => false, 'custom' => true]);

it('preserves normalized folder and custom methods through the real catalog cache', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->permissions([
            StaticSource::names('app', 'orders.view'),
            new StaticSource('clients', ClientPermission::definitions(), policies: [
                PolicyBinding::for(ClientPermission::ViewOwnProfile, AttributedClientPolicy::class),
            ]),
        ])
        ->policies([PolicyBinding::for('orders.view', ReviewPolicy::class)]));

    $this->bootCatalogPanels([AdminPanel::class]);
    $live = app(PanelRegistry::class)->catalog('admin');
    $expected = ['orders.view' => 'arbitraryName', 'clients.view_own_profile' => 'ownProfile'];

    expect($live->snapshot()['binding_methods'])->toBe($expected)
        ->and(StaticSource::$reads)->toBe(['app' => 1, 'clients' => 1]);

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require self::$catalogCachePath;
    expect($file['panels']['admin']['catalog']['binding_methods'])->toBe($expected);

    $this->bootCatalogPanels([AdminPanel::class]);
    $cached = app(PanelRegistry::class)->catalog('admin');

    expect(StaticSource::$reads)->toBe([])
        ->and($cached)->not->toBe($live)
        ->and($cached->snapshot())->toBe($live->snapshot())
        ->and($cached->bindingMethod('orders.view'))->toBe('arbitraryName')
        ->and($cached->bindingMethod('clients.view_own_profile'))->toBe('ownProfile');
});
