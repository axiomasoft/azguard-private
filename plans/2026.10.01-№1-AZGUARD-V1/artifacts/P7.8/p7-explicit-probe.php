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
use Filament\Actions\DeleteAction;
use Filament\Actions\DetachAction;
use Filament\Actions\ImportAction;
use Filament\Actions\SelectAction;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(TestCase::class, BootsFilament::class)->afterEach(fn () => FilamentFixture::reset());

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
});

$forge = static function (Testable $component, string $name, int|string|null $record = null, bool $bulk = false): Testable {
    $context = ['table' => true, ...($record === null ? [] : ['recordKey' => (string) $record]), ...($bulk ? ['bulk' => true] : [])];

    return $component->call('mountAction', $name, [], $context)->call('callMountedAction');
};

it('probe record before() without action() deletes a visible order', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->before(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe record after() without action() deletes a visible order', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->after(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe record action() that ignores the record is refused', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->action(static function (): void {
            Order::query()->whereKey(1)->delete();
        });

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe visible(false) does not run the action', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->visible(static fn (): bool => false)
        ->action(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe visible() that is false for the forged record does not delete it', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->visible(static fn (Order $record): bool => (int) $record->getKey() === 1)
        ->action(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 2);

    expect(Order::query()->whereKey(2)->exists())->toBeTrue()
        ->and(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe visible(fn true) deletes without delete permission', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->visible(static fn (): bool => true)
        ->action(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe hidden(fn false) deletes without delete permission', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('purge')
        ->hidden(static fn (): bool => false)
        ->action(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe bulk action that does not ask for records deletes the order', function () use ($forge): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static function (): void {
            Order::query()->whereKey(1)->delete();
        });

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1']), 'purge', bulk: true);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe bulk action reads the selection from the component', function () use ($forge): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static function (ListOrders $livewire): void {
            foreach ($livewire->getSelectedTableRecords() as $record) {
                $record->delete();
            }
        });

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']), 'purge', bulk: true);

    expect(Order::query()->whereKey([1, 2])->count())->toBe(0);
});

it('probe bulk before() deletes without authorizeIndividualRecords', function () use ($forge): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->before(static function (): void {
            Order::query()->whereKey(1)->delete();
        });

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1']), 'purge', bulk: true);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe bulk recordsQuery deletes the selected rows', function () use ($forge): void {
    OrderResource::$extras['toolbarActions'][] = static fn (): BulkAction => BulkAction::make('purge')
        ->action(static function (Builder $recordsQuery): void {
            $recordsQuery->delete();
        });

    $forge(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']), 'purge', bulk: true);

    expect(Order::query()->whereKey([1, 2])->count())->toBe(0)
        ->and(Order::query()->whereKey(3)->exists())->toBeTrue();
});

it('probe column closure action deletes the row', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextColumn => TextColumn::make('title')
        ->state(static fn (Order $record): string => (string) $record->number)
        ->action(static fn (Order $record) => $record->delete());

    Livewire::test(ListOrders::class)->call('callTableColumnAction', 'title', '1');

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe column Action object is refused', function () use ($forge): void {
    OrderResource::$extras['columns'][] = static fn (): TextColumn => TextColumn::make('title')
        ->state(static fn (Order $record): string => (string) $record->number)
        ->action(Action::make('purge')->action(static fn (Order $record) => $record->delete()));

    try {
        Livewire::test(ListOrders::class)->call('mountTableAction', 'purge', '1');
    } catch (Throwable $error) {
        expect($error->getMessage())->toContain('not a failure of the probe');
    }

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe SelectAction deletes without a permission', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => SelectAction::make('status')
        ->options(['x' => 'X'])
        ->action(static fn (Order $record) => $record->delete());

    $forge(Livewire::test(ListOrders::class), 'status', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeFalse();
});

it('probe ImportAction mounts without orders.create', function (): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => ImportAction::make()
        ->importer(ProbeOrderImporter::class);

    $component = Livewire::test(ListOrders::class)->call('mountAction', 'import', [], ['table' => true, 'recordKey' => '1']);

    expect($component->instance()->mountedActions[0]['name'] ?? null)->toBe('import');
});

it('probe ActionGroup child is refused', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => ActionGroup::make([
        Action::make('purge')->action(static fn (Order $record) => $record->delete()),
    ]);

    $forge(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe modal footer action is refused', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => Action::make('open')
        ->modalHeading('Open')
        ->modalSubmitAction(false)
        ->extraModalFooterActions([
            Action::make('purge')->action(static fn (Order $record) => $record->delete()),
        ]);

    $component = Livewire::test(ListOrders::class);
    $component->call('mountAction', 'open', [], ['table' => true, 'recordKey' => '1']);
    $component->call('mountAction', 'purge');
    $component->call('callMountedAction');

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe DetachAction does not throw into a write', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => DetachAction::make();
    $threw = false;

    try {
        $forge(Livewire::test(ListOrders::class), 'detach', 1);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse()
        ->and(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('probe DeleteAction still refuses without delete and deletes with it', function () use ($forge): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => DeleteAction::make();

    $forge(Livewire::test(ListOrders::class), 'delete', 1);
    expect(Order::query()->whereKey(1)->exists())->toBeTrue();

    GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']);
    $forge(Livewire::test(ListOrders::class), 'delete', 1);
    expect(Order::query()->whereKey(1)->exists())->toBeFalse();

    $forge(Livewire::test(ListOrders::class), 'delete', 4);
    expect(Order::query()->whereKey(4)->exists())->toBeTrue();
});

it('probe disabled(false) saves an inline column without update', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextInputColumn => TextInputColumn::make('number')->disabled(false);

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'HACKED');

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->toBe('HACKED');
});

it('probe SelectColumn CheckboxColumn ToggleColumn stay unchanged without update', function (): void {
    OrderResource::$extras['columns'][] = static fn (): SelectColumn => SelectColumn::make('number')->options(['A-1' => 'A-1', 'HACKED' => 'HACKED']);
    OrderResource::$extras['columns'][] = static fn (): CheckboxColumn => CheckboxColumn::make('secret');
    OrderResource::$extras['columns'][] = static fn (): ToggleColumn => ToggleColumn::make('locked');

    $component = Livewire::test(ListOrders::class);
    $component->call('updateTableColumnState', 'number', '1', 'HACKED');
    $component->call('updateTableColumnState', 'secret', '1', true);
    $component->call('updateTableColumnState', 'locked', '1', true);

    $order = Order::query()->findOrFail(1);
    expect($order->getAttribute('number'))->toBe('A-1')
        ->and((bool) $order->getAttribute('secret'))->toBeFalse()
        ->and((bool) $order->getAttribute('locked'))->toBeFalse();
});

it('probe reorderTable rewrites visible rows without update or reorder', function (): void {
    $remove = Table::configureUsing(static function (Table $table): void {
        $table->reorderable('number');
    });

    try {
        Livewire::test(ListOrders::class)->call('reorderTable', ['2', '1', '4']);
    } finally {
        if (is_callable($remove)) {
            $remove();
        }
    }

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->not->toBe('A-1');
});

it('probe secret row is not rewritten by reorder', function (): void {
    $remove = Table::configureUsing(static function (Table $table): void {
        $table->reorderable('number');
    });

    try {
        Livewire::test(ListOrders::class)->call('reorderTable', ['3', '1']);
    } finally {
        if (is_callable($remove)) {
            $remove();
        }
    }

    expect(Order::query()->findOrFail(3)->getAttribute('number'))->toBe('S-3');
});

/**
 * Minimal importer so ImportAction can be constructed. The probe only checks that the action mounts.
 */
final class ProbeOrderImporter extends \Filament\Actions\Imports\Importer
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [
            \Filament\Actions\Imports\ImportColumn::make('number'),
        ];
    }

    public function resolveRecord(): ?Order
    {
        return new Order;
    }

    public static function getCompletedNotificationBody(\Filament\Actions\Imports\Models\Import $import): string
    {
        return 'done';
    }
}
