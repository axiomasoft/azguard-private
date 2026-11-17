<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Livewire\Livewire;
use Packman\Filament\Security\Surface\RefusesSurfaceBoot;
use Packman\Filament\Security\Surface\RefusesSurfaceCalls;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('passes Packman boot and call guards for an authorized AzGuard relation manager', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));

    $test = Livewire::test(ProductsRelationManager::class, [
        'ownerRecord' => Order::query()->findOrFail(1),
        'pageClass' => ListOrders::class,
    ])->assertSuccessful();

    $hook = new RefusesSurfaceBoot;
    $hook->setComponent($test->instance());
    $hook->mount();
    $hook->hydrate();
    (new RefusesSurfaceCalls)($test->instance());
    $test->call('$refresh')->assertSuccessful()
        ->assertCanSeeTableRecords(Product::query()->whereKey(1)->get())
        ->assertCanNotSeeTableRecords(Product::query()->whereKey(2)->get());

    GateWorld::revoke('products.view_any');
    expect(fn () => $hook->hydrate())->toThrow(HttpException::class)
        ->and(fn () => (new RefusesSurfaceCalls)($test->instance()))->toThrow(HttpException::class);
});
