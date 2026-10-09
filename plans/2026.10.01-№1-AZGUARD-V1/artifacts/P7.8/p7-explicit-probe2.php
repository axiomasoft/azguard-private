<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(TestCase::class, BootsFilament::class)->afterEach(fn () => FilamentFixture::reset());

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
});

it('debug reorder flags', function (): void {
    $remove = Table::configureUsing(static function (Table $table): void {
        $table->reorderable('number');
    });

    try {
        $component = Livewire::test(ListOrders::class);
        $table = $component->instance()->getTable();
        fwrite(STDERR, json_encode([
            'column' => $table->getReorderColumn(),
            'reorderable' => $table->isReorderable(),
            'authorized' => $table->isReorderAuthorized(),
        ])."\n");
        $component->call('reorderTable', ['2', '1', '4']);
        fwrite(STDERR, 'number='.Order::query()->findOrFail(1)->getAttribute('number')."\n");
    } finally {
        if (is_callable($remove)) {
            $remove();
        }
    }

    expect(true)->toBeTrue();
});

it('probe ActionGroup child via the table', function (): void {
    $component = Livewire::test(ListOrders::class);
    $component->instance()->getTable()->recordActions(ActionGroup::make([
        Action::make('purge')->action(static fn (Order $record) => $record->delete()),
    ]));
    $component->call('mountAction', 'purge', [], ['table' => true, 'recordKey' => '1'])->call('callMountedAction');

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe modal footer through callAction', function (): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('open')
        ->modalHeading('Open')
        ->modalSubmitAction(false)
        ->extraModalFooterActions([
            Action::make('purge')->action(static fn (Order $record) => $record->delete()),
        ]);

    $component = Livewire::test(ListOrders::class);

    try {
        $component->callAction([
            TestAction::make('open')->table('1'),
            TestAction::make('purge'),
        ]);
        $called = 'returned';
    } catch (Throwable $error) {
        $called = $error::class.': '.$error->getMessage();
    }

    fwrite(STDERR, "modal={$called} exists=".(Order::query()->whereKey(1)->exists() ? 'yes' : 'no')."\n");
    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe bulk Collection is still empty', function (): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static fn (Collection $records) => $records->each->delete());

    Livewire::test(ListOrders::class)
        ->set('selectedTableRecords', ['1', '2'])
        ->call('mountAction', 'purge', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');

    expect(Order::query()->whereKey([1, 2])->count())->toBe(2);
});

it('probe recordsQuery does not delete a hidden id that was selected', function (): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static function (\Illuminate\Database\Eloquent\Builder $recordsQuery): void {
            $recordsQuery->delete();
        });

    Livewire::test(ListOrders::class)
        ->set('selectedTableRecords', ['1', '3'])
        ->call('mountAction', 'purge', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');

    expect(Order::query()->whereKey(1)->exists())->toBeFalse()
        ->and(Order::query()->whereKey(3)->exists())->toBeTrue();
});
