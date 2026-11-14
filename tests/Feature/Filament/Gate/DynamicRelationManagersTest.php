<?php

declare(strict_types=1);

use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\Fixtures\Filament\Resources\ProductResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Livewire;

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));
    RuntimeProductsRelationManager::$booted = 0;
});

it('refuses a runtime relation manager before its boot can change a record', function (): void {
    Livewire::test(RuntimeProductsRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertForbidden();

    expect(RuntimeProductsRelationManager::$booted)->toBe(0)
        ->and(Order::query()->findOrFail(1)->number)->toBe('A-1');
});

it('refuses a runtime relation manager that replaces the trait visibility method', function (): void {
    Livewire::test(ReplacedVisibilityRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertForbidden();
});

it('refuses a runtime relation manager whose trait names no related resource', function (): void {
    Livewire::test(UnmappedProductsRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertForbidden();

    expect(RuntimeProductsRelationManager::$booted)->toBe(0);
});

it('refuses a runtime relation manager before boot when its view-any permission is missing', function (): void {
    GateWorld::revoke('products.view_any');

    Livewire::test(GuardedRuntimeProductsRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertForbidden();

    expect(RuntimeProductsRelationManager::$booted)->toBe(0)
        ->and(Order::query()->findOrFail(1)->number)->toBe('A-1');
});

it('leaves a runtime relation manager of an excluded owner to Filament', function (): void {
    FilamentFixture::$exclude = ['resources' => [OrderResource::class]];
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['products.view_any', 'products.view']));
    RuntimeProductsRelationManager::$booted = 0;

    Livewire::test(RuntimeProductsRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertSuccessful()->assertCanSeeTableRecords(Product::query()->whereKey([1, 2])->get());

    expect(RuntimeProductsRelationManager::$booted)->toBeGreaterThan(0);
});

class RuntimeProductsRelationManager extends RelationManager
{
    public static int $booted = 0;

    protected static string $relationship = 'products';

    protected static bool $shouldSkipAuthorization = true;

    public function boot(): void
    {
        self::$booted++;
        Order::query()->whereKey(1)->update(['number' => 'UNGUARDED']);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}

final class ReplacedVisibilityRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static string $relationship = 'products';

    protected static ?string $azguardResource = ProductResource::class;

    protected function makeTable(): Table
    {
        return parent::makeTable();
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}

final class UnmappedProductsRelationManager extends RuntimeProductsRelationManager
{
    use AuthorizesRelationManager;
}

final class GuardedRuntimeProductsRelationManager extends RuntimeProductsRelationManager
{
    use AuthorizesRelationManager;

    protected static ?string $azguardResource = ProductResource::class;
}
