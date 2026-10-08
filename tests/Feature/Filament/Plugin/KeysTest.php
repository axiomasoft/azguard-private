<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Filament\Authorization\FilamentKey;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\BadSlugResource;
use AzGuard\Tests\Fixtures\Filament\Resources\KeyedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\NestedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Widgets\KeyedSalesWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\Other\SalesWidget as OtherSalesWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\Stats\SalesWidget;
use Filament\Facades\Filament;
use Filament\Resources\ResourceConfiguration;

/*
 * Keys of the resources, pages and widgets of a Filament panel (Keys): {slug}.{ability}, pages.{slug}, widgets.{name};
 * the model of a resource never takes part, a key is unique and a segment follows the grammar.
 */

/** @return list<string> */
function filamentKeys(): array
{
    $panel = Filament::getPanel('admin');

    return array_map(static fn (FilamentKey $key): string => $key->local, AzGuardPlugin::get('admin')->keys($panel)->all());
}

it('builds a key per ability from the slug of a resource, never from its model', function (): void {
    FilamentFixture::$abilities = ['view_any', 'force_delete'];
    $this->bootFilament();

    expect(filamentKeys())->toBe([
        'orders.view_any', 'orders.force_delete',
        'archived-orders.view_any', 'archived-orders.force_delete',
        'pages.probe',
    ]);
});

it('gives the default abilities of the configuration to a resource', function (): void {
    $keys = filamentKeys();

    expect($keys)->toContain('orders.view_any', 'orders.delete_any', 'orders.force_delete_any', 'orders.restore_any', 'orders.replicate', 'orders.reorder')
        ->and(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'orders.')))->toHaveCount(12);
});

it('makes a nested segment of a slash in a slug and of a resource configuration', function (): void {
    FilamentFixture::$abilities = ['view'];
    FilamentFixture::$resources = [NestedOrderResource::class, ResourceConfiguration::make(OrderResource::class, 'special')];
    FilamentFixture::$pages = [];
    $this->bootFilament();

    expect(filamentKeys())->toBe(['shop.orders.view', 'orders.special.view']);
});

it('replaces the computed segment with $azguardKey', function (): void {
    FilamentFixture::$abilities = ['view'];
    FilamentFixture::$resources = [KeyedOrderResource::class];
    FilamentFixture::$pages = [];
    FilamentFixture::$widgets = [KeyedSalesWidget::class, SalesWidget::class];
    $this->bootFilament();

    expect(filamentKeys())->toBe(['keyed-orders.view', 'widgets.other-sales', 'widgets.sales-widget']);
});

it('labels a resource key with its plural label and navigation group and a page key with its navigation label', function (): void {
    FilamentFixture::$abilities = ['view_any'];
    $this->bootFilament();
    $keys = AzGuardPlugin::get('admin')->keys(Filament::getPanel('admin'))->all();

    expect($keys[0]->label)->toBe('View any: Orders')
        ->and($keys[0]->group)->toBe('Sales')
        ->and($keys[0]->class)->toBe(OrderResource::class)
        ->and($keys[0]->model)->toBe(Order::class)
        ->and($keys[1]->group)->toBeNull()
        ->and($keys[2]->class)->toBe(ProbePage::class)
        ->and($keys[2]->label)->toBe('Probe Page');
});

it('leaves excluded classes without a key', function (): void {
    FilamentFixture::$abilities = ['view'];
    FilamentFixture::$exclude = ['resources' => [ArchivedOrderResource::class], 'pages' => [ProbePage::class]];
    $this->bootFilament();

    expect(filamentKeys())->toBe(['orders.view']);
});

it('refuses two classes with one key when the Filament panel boots, and names the way out', function (): void {
    FilamentFixture::$widgets = [SalesWidget::class, OtherSalesWidget::class];
    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())
        ->toThrow(ConfigurationException::class, 'widgets.sales-widget');

    try {
        Filament::getPanel('admin')->boot();
    } catch (ConfigurationException $error) {
        expect($error->getMessage())->toContain(SalesWidget::class, OtherSalesWidget::class, '$azguardKey')
            ->and($error->code())->toBe('invalid_configuration.filament_key_duplicate');
    }
});

it('refuses a slug that is not a permission segment', function (): void {
    FilamentFixture::$resources = [BadSlugResource::class];
    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())
        ->toThrow(ConfigurationException::class, 'Set protected static ?string $azguardKey');
});
