<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\SellerPanel;
use AzGuard\Tests\Fixtures\Panels\User;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));
});

/**
 * @param  Closure(PanelBuilder): mixed  $admin
 */
function bootPrefixPanels(Closure $admin): PanelResolver
{
    AdminPanel::describe(static fn (PanelBuilder $panel): mixed => $admin($panel->for(User::class)->permissions([OrderPermission::class])));
    test()->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]]);

    return app(PanelResolver::class);
}

it('uses the panel id as the prefix by default', function (): void {
    $resolver = bootPrefixPanels(static fn (PanelBuilder $panel): PanelBuilder => $panel);

    expect(app(PanelRegistry::class)->get('admin')->prefix())->toBe('admin')
        ->and($resolver->resolve(new User, 'admin.orders.view'))->toMatchArray(['step' => PanelResolver::EXPLICIT])
        ->and($resolver->resolve(new User, 'admin.orders.view')['key']?->full())->toBe('admin:orders.view')
        ->and($resolver->resolve(new User, 'cabinet.orders.view')['key']?->full())->toBe('cabinet:orders.view');
});

it('uses a custom prefix instead of the panel id', function (): void {
    $resolver = bootPrefixPanels(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('backoffice'));

    expect($resolver->resolve(new User, 'backoffice.orders.view')['key']?->full())->toBe('admin:orders.view')
        ->and($resolver->owner('backoffice.orders.view')?->id())->toBe('admin');
});

it('names no panel by prefix when the prefix is turned off', function (): void {
    $resolver = bootPrefixPanels(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false));

    expect(app(PanelRegistry::class)->get('admin')->prefix())->toBeNull()
        ->and(fn () => $resolver->resolve(new User, 'admin.orders.view'))->toThrow(PanelNotResolvedException::class)
        ->and($resolver->resolve(new User, 'orders.view', 'admin')['key']?->full())->toBe('admin:orders.view');
});

it('keeps keys of enums and full names when the prefix changes, and does not alias the old prefix', function (string|bool $prefix): void {
    $resolver = bootPrefixPanels(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix($prefix));

    expect($resolver->resolve(new User, OrderPermission::View, 'admin')['key']?->full())->toBe('admin:orders.view')
        ->and($resolver->resolve(new User, OrderPermission::View)['key']?->full())->toBe('admin:orders.view')
        ->and($resolver->resolve(new User, 'admin:orders.view')['key']?->full())->toBe('admin:orders.view');

    if ($prefix !== true) {
        expect($resolver->owner('admin.orders.view'))->toBeNull()
            ->and(fn () => $resolver->resolve(new User, 'admin.orders.view'))->toThrow(PanelNotResolvedException::class)
            ->and($resolver->resolve(new User, 'admin.orders.view', 'admin')['key']?->local())->toBe('admin.orders.view');
    }
})->with(['default' => true, 'custom' => 'backoffice', 'off' => false]);

it('rejects a prefix shared by two panels when the panels are compiled', function (Closure $admin, Closure $cabinet): void {
    AdminPanel::describe($admin);
    CabinetPanel::describe($cabinet);

    try {
        $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class, SellerPanel::class]]);
        $this->fail('The panels compiled with a shared prefix.');
    } catch (PrefixConflictException $e) {
        expect($e->code())->toBe('prefix_conflict')
            ->and($e->getMessage())->toContain('"admin" and "cabinet"');
    }
})->with([
    'the same custom prefix' => [
        fn (PanelBuilder $panel) => $panel->resourcePrefix('office'),
        fn (PanelBuilder $panel) => $panel->resourcePrefix('office'),
    ],
    'a custom prefix equal to the default prefix of another panel' => [
        fn (PanelBuilder $panel) => $panel,
        fn (PanelBuilder $panel) => $panel->resourcePrefix('admin'),
    ],
]);

it('lets several panels turn the prefix off', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false));

    $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]]);

    expect(app(PanelRegistry::class)->forPrefix('admin'))->toBeNull()
        ->and(array_keys(app(PanelRegistry::class)->all()))->toBe(['admin', 'cabinet']);
});
