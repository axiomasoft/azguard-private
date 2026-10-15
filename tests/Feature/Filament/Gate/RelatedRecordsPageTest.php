<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ManageOrderProducts;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * The relation of a record on a page of its own (ManageRelatedRecords) and the bulk detach of a relation manager are
 * changed with `update` of the owner, as the attach of a relation manager is.
 */

uses(BootsFilament::class);

afterEach(fn () => FilamentFixture::reset());

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    $this->order = Order::query()->findOrFail(1);
    $this->order->products()->sync([1, 3]);
    $this->page = fn () => Livewire::test(ManageOrderProducts::class, ['record' => $this->order->getKey()]);
    $this->ids = fn (): array => $this->order->products()->pluck('products.id')->sort()->values()->all();
});

it('refuses to change the relation on the page with read grants alone', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));
    $page = ($this->page)();

    $page->assertActionHidden(TestAction::make('attach')->table())
        ->assertActionHidden(TestAction::make('detach')->table(Product::query()->findOrFail(1)));
    expect(($this->ids)())->toBe([1, 3]);
});

it('changes the relation on the page with update of the owner', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update', 'products.view_any', 'products.view']));

    ($this->page)()->callAction(TestAction::make('attach')->table(), ['recordId' => 4])->assertHasNoActionErrors();
    expect(($this->ids)())->toBe([1, 3, 4]);

    ($this->page)()->callAction(TestAction::make('detach')->table(Product::query()->findOrFail(4)))->assertHasNoActionErrors();
    expect(($this->ids)())->toBe([1, 3]);
});

it('detaches the selected records in bulk only with update of the owner', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));
    $page = ($this->page)()->selectTableRecords([1, 3]);

    $page->assertActionHidden(TestAction::make('detach')->table()->bulk());
    expect(($this->ids)())->toBe([1, 3]);

    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update', 'products.view_any', 'products.view']));
    ($this->page)()->selectTableRecords([1, 3])->callAction(TestAction::make('detach')->table()->bulk())->assertHasNoActionErrors();
    expect(($this->ids)())->toBe([]);
});
