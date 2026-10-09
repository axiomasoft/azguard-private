<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\SelectAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Forged Livewire calls on a guarded resource by a user who only views: lifecycle hooks without a handler, bulk
 * callbacks that never ask for the records, column closures, and actions that Filament leaves undecided. Nothing is written.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
});

/** Mounts and calls an action by its name, whether the page shows it or not. */
function forgeAction(Testable $component, string $name, int|string|null $record = null, bool $bulk = false): Testable
{
    $context = ['table' => true, ...($record === null ? [] : ['recordKey' => (string) $record]), ...($bulk ? ['bulk' => true] : [])];

    return $component->call('mountAction', $name, [], $context)->call('callMountedAction');
}

it('runs no before() or after() hook of a record action that has no handler', function (string $hook): void {
    FilamentFixture::$recordActions = [Action::make('purge')->{$hook}(static fn (Order $record) => $record->delete())];

    forgeAction(Livewire::test(ListOrders::class), 'purge', 1);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
})->with(['before', 'after']);

it('runs no bulk action that never asks for the selected records', function (): void {
    FilamentFixture::$toolbarActions = [
        BulkAction::make('purge')->action(static function (): void {
            Order::query()->whereKey(1)->delete();
        }),
    ];

    forgeAction(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1']), 'purge', bulk: true);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('runs no bulk hook, selection read or records query without the permission of every selected record', function (string $kind): void {
    FilamentFixture::$toolbarActions = [match ($kind) {
        'before' => BulkAction::make('purge')->before(static function (): void {
            Order::query()->whereKey(1)->delete();
        }),
        'selection' => BulkAction::make('purge')->action(static function (ListOrders $livewire): void {
            foreach ($livewire->getSelectedTableRecords() as $record) {
                $record->delete();
            }
        }),
        'query' => BulkAction::make('purge')->action(static function (Builder $recordsQuery): void {
            $recordsQuery->delete();
        }),
    }];

    forgeAction(Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '3']), 'purge', bulk: true);

    expect(Order::query()->whereKey([1, 3])->count())->toBe(2);
})->with(['before', 'selection', 'query']);

it('runs no raw closure action of a column', function (): void {
    FilamentFixture::$tableColumns = [TextColumn::make('title')->state(static fn (Order $record): string => (string) $record->number)->action(static fn (Order $record) => $record->delete())];

    Livewire::test(ListOrders::class)->call('callTableColumnAction', 'title', '1');

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('runs no SelectAction handler and mounts no ImportAction without the permission', function (): void {
    FilamentFixture::$recordActions = [
        SelectAction::make('status')->options(['x' => 'X'])->action(static fn (Order $record) => $record->delete()),
        ImportAction::make('import')->importer(ForgedImportOrders::class),
    ];

    forgeAction(Livewire::test(ListOrders::class), 'status', 1);
    $component = Livewire::test(ListOrders::class)->call('mountAction', 'import', [], ['table' => true, 'recordKey' => '1']);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue()
        ->and($component->instance()->mountedActions)->toBe([]);
});

it('does not save an inline column whose application enabled it with disabled(false) while the user may not update', function (): void {
    FilamentFixture::$tableColumns = [TextInputColumn::make('number')->disabled(false)];

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'HACKED');

    expect(Order::query()->findOrFail(1)->getAttribute('number'))->not->toBe('HACKED');
});

/** The smallest importer that lets an ImportAction be built. */
final class ForgedImportOrders extends Importer
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('number')];
    }

    public function resolveRecord(): ?Order
    {
        return new Order;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return 'done';
    }
}
