<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Internal\Authorization\FilamentContext;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\ManagedRelationPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\BadAbilityRelationManager;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ManagedProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * A relation manager may declare the ability of its owner resource that changing the relation needs, instead of `update`.
 * The ability is a key of the owner resource, so it is granted, listed and generated as any other.
 */

uses(BootsFilament::class);

afterEach(fn () => FilamentFixture::reset());

beforeEach(function (): void {
    GateWorld::prepare();
    FilamentFixture::$relations = [ManagedProductsRelationManager::class];
    FilamentFixture::$guardPermissions = [OrderPermission::class, ManagedRelationPermission::class];
    $this->bootFilament();
    GateWorld::seed();
    $this->order = Order::query()->findOrFail(1);
    $this->order->products()->detach(2);
    $this->manager = fn () => Livewire::test(ManagedProductsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ListOrders::class]);
});

it('adds the declared ability to the keys of the owner resource', function (): void {
    $keys = array_map(static fn ($key): string => $key->local, AzGuardPlugin::get('admin')->keys(Filament::getPanel('admin'))->all());

    expect($keys)->toContain('orders.manage_products');
});

it('changes the relation with the declared ability and not with update of the owner', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.update', 'products.view_any', 'products.view']));
    ($this->manager)()->assertActionHidden(TestAction::make('attach')->table());

    GateWorld::serve(GateWorld::grant(['orders.manage_products', 'products.view_any', 'products.view']));
    ($this->manager)()->callAction(TestAction::make('attach')->table(), ['recordId' => 4])->assertHasNoActionErrors();
    expect($this->order->products()->pluck('products.id')->sort()->values()->all())->toBe([1, 4]);

    ($this->manager)()->callAction(TestAction::make('detach')->table(Product::query()->findOrFail(4)))->assertHasNoActionErrors();
    expect($this->order->products()->pluck('products.id')->all())->toBe([1]);
});

it('refuses a relation ability that is not snake_case', function (): void {
    expect(fn () => FilamentContext::declaredRelationAbility(BadAbilityRelationManager::class))->toThrow(InvalidConfigurationException::class, 'snake_case');
});
