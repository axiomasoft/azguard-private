<?php

declare(strict_types=1);

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The surfaces Filament leaves open on a resource table: an action or bulk action of the application and an inline
 * editable column. A panel that enforces refuses them until the application decides them or the user may update the row.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
});

/** What a forged Livewire request does: mounts and calls an action by its name, whether the page shows it or not. */
$forge = static function (Testable $component, string $name, int|string|null $record = null, bool $bulk = false): Testable {
    $context = ['table' => true, ...($record === null ? [] : ['recordKey' => (string) $record]), ...($bulk ? ['bulk' => true] : [])];

    return $component->call('mountAction', $name, [], $context)->call('callMountedAction');
};

$purge = static fn (): Action => Action::make('purge')->action(static fn (Order $record) => $record->delete());
$mayDelete = static fn (Order $record): bool => FilamentGate::resource(OrderResource::class, 'delete', $record)->allowed();

it('F1 refuses the record action of the application that says nothing about who may run it', function () use ($forge, $purge): void {
    OrderResource::$extras['recordActions'][] = $purge;
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('F1 runs the record action of the application that authorizes the record, and only for the record the user may delete', function () use ($forge, $mayDelete): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->authorize($mayDelete)
        ->action(static fn (Order $record) => $record->delete());
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']));

    $forge(Livewire::test(ListOrders::class), 'purge', 4);
    expect(Order::query()->whereKey(4)->exists())->toBeTrue();

    $forge(Livewire::test(ListOrders::class), 'purge', 1);
    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('F1 refuses the authorized record action for a user without the permission', function () use ($forge, $mayDelete): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->authorize($mayDelete)
        ->action(static fn (Order $record) => $record->delete());
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('F1 accepts visible() or hidden() with a condition as the decision of the application', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->visible(static fn (Order $record): bool => $record->getKey() === 1)
        ->action(static fn (Order $record) => $record->delete());
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('F1 leaves an action without a handler, such as a link, to the application', function (): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('open')->url('/orders');
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->assertActionVisible(TestAction::make('open')->table(1));
});

it('F1 refuses the bulk action of the application that does not decide every record', function () use ($forge): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static fn (Collection $records) => $records->each->delete());
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']), 'purge', bulk: true);

    expect(Order::query()->whereKey([1, 2])->count())->toBe(2);
});

it('F1 runs the bulk action of the application only on the records it authorizes', function () use ($forge, $mayDelete): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->authorizeIndividualRecords(static fn (Model $record): Response => $mayDelete($record) ? Response::allow() : Response::deny('no'))
        ->action(static fn (Collection $records) => $records->each->delete());
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']));

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '4']), 'purge', bulk: true);

    expect(Order::query()->pluck('id')->sort()->values()->all())->toBe([2, 3, 4]);
});

it('F1 refuses the detach of selected records of a relation manager that nobody decided', function () use ($forge): void {
    GateWorld::serve(GateWorld::grant(['products.view_any', 'products.view']));
    $order = Order::query()->findOrFail(1);

    $forge(Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ListOrders::class])->set('selectedTableRecords', ['1']), 'detach', bulk: true);

    expect($order->products()->pluck('products.id')->all())->toBe([1, 2]);
});

it('F2 leaves a visible row alone when an inline column is edited without the update permission', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextInputColumn => TextInputColumn::make('number');
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'HACKED');

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->not->toBe('HACKED');
});

it('F2 saves an inline column for the row the user may update', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextInputColumn => TextInputColumn::make('number');
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update']));

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'RENAMED');

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->toBe('RENAMED');
});

it('F2 leaves an inline column that sets disabled() itself to the application', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextInputColumn => TextInputColumn::make('number')->disabled();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update']));

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'RENAMED');

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->not->toBe('RENAMED');
});

it('does not touch the actions and columns of a Filament panel that does not enforce', function () use ($forge, $purge): void {
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    GateWorld::seed();
    OrderResource::$extras['recordActions'][] = $purge;
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});
