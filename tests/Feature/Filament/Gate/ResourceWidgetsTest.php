<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\ResourceWidgetPermission;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListWidgetOrders;
use AzGuard\Tests\Fixtures\Filament\Resources\WidgetOrderResource;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\DynamicWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\ProtectedWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\OfResource\UnprotectedWidget;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * The widgets a resource declares are widgets of the panel for AzGuard: decided, in the catalog and generated, as those
 * the Filament panel lists.
 */

uses(BootsFilament::class);

afterEach(function (): void {
    WidgetOrderResource::$withUnprotected = true;
    FilamentFixture::reset();
});

it('refuses a widget of a resource that no AzGuard trait decides, in a panel that enforces', function (): void {
    GateWorld::prepare();
    FilamentFixture::$resources = [WidgetOrderResource::class];
    FilamentFixture::$widgets = [];

    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())->toThrow(InvalidConfigurationException::class, UnprotectedWidget::class);
});

it('keeps the widgets of a resource in the keys and hides them until the permission is granted', function (): void {
    GateWorld::prepare();
    WidgetOrderResource::$withUnprotected = false;
    FilamentFixture::$resources = [WidgetOrderResource::class];
    FilamentFixture::$widgets = [];
    FilamentFixture::$guardPermissions = [OrderPermission::class, ResourceWidgetPermission::class];
    $this->bootFilament();
    GateWorld::seed();
    $keys = array_map(static fn ($key) => $key->class, AzGuardPlugin::get('admin')->keys(Filament::getPanel('admin'))->all());

    expect($keys)->toContain(ProtectedWidget::class);

    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    expect(ProtectedWidget::canView())->toBeFalse();
    Livewire::test(ListWidgetOrders::class)->assertSuccessful()->assertDontSeeLivewire(ProtectedWidget::class);

    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'widgets.protected-widget']));
    expect(ProtectedWidget::canView())->toBeTrue();
    Livewire::test(ListWidgetOrders::class)->assertSuccessful()->assertSeeLivewire(ProtectedWidget::class);
});

it('puts the widgets of a resource in the catalog of the resources definitions', function (): void {
    WidgetOrderResource::$withUnprotected = false;
    FilamentFixture::$resources = [WidgetOrderResource::class];
    FilamentFixture::$widgets = [];
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
    $this->bootFilament();

    expect(AzGuard::panel('admin')->catalog()->has('widgets.protected-widget'))->toBeTrue();
});

it('leaves an excluded widget of a resource to Filament', function (): void {
    GateWorld::prepare();
    FilamentFixture::$resources = [WidgetOrderResource::class];
    FilamentFixture::$widgets = [];
    FilamentFixture::$exclude = ['widgets' => [UnprotectedWidget::class]];
    $this->bootFilament();

    expect(UnprotectedWidget::canView())->toBeTrue();
});

it('refuses at run time a widget that no key names and no trait decides', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any']));

    Livewire::test(DynamicWidget::class)->assertForbidden();
});

it('shows at run time a widget the panel excludes', function (): void {
    GateWorld::prepare();
    FilamentFixture::$exclude = ['widgets' => [DynamicWidget::class]];
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any']));

    Livewire::test(DynamicWidget::class)->assertSuccessful();
});
