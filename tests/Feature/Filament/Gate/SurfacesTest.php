<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Laravel\Queue\PanelContext;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Filament\Exports\OrderExporter;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Jobs\ExportCompletion;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * V96, Filament surfaces: a bulk action decides every selected record, attaching takes only a record the user may view,
 * global search finds no hidden record, and an export checks its rows again when its job runs.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
});

it('V96 deletes in bulk only the selected records the user may delete, and says that one was refused', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));

    Livewire::test(ListOrders::class)
        ->selectTableRecords([1, 4])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified();

    expect(Order::query()->pluck('id')->sort()->values()->all())->toBe([2, 3, 4]);
});

it('V96 refuses the bulk delete without the permission for any record', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']));

    Livewire::test(ListOrders::class)
        ->selectTableRecords([1, 2])
        ->assertActionHidden(TestAction::make('delete')->table()->bulk());

    expect(Order::query()->count())->toBe(4);
});

it('V96 attaches only a record the user may view', function (): void {
    GateWorld::serve(GateWorld::grant(['products.view_any', 'products.view']));
    $order = Order::query()->findOrFail(1);
    $order->products()->detach(2);
    $manager = static fn () => Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ListOrders::class]);

    $manager()->callAction(TestAction::make('attach')->table(), ['recordId' => 2])->assertHasActionErrors(['recordId']);
    expect($order->products()->pluck('products.id')->all())->toBe([1]);

    $manager()->callAction(TestAction::make('attach')->table(), ['recordId' => 4])->assertHasNoActionErrors();
    expect($order->products()->pluck('products.id')->sort()->values()->all())->toBe([1, 4]);
});

it('offers every record to attach when the panel does not enforce: the limit above is the one of AzGuard', function (): void {
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['products.view_any', 'products.view']));
    $order = Order::query()->findOrFail(1);
    $order->products()->detach(2);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ListOrders::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => 2])->assertHasNoActionErrors();

    expect($order->products()->pluck('products.id')->sort()->values()->all())->toBe([1, 2]);
});

it('V96 finds no hidden record in global search', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    expect(OrderResource::canGloballySearch())->toBeTrue()
        ->and(OrderResource::getGlobalSearchResults('S-3'))->toHaveCount(0)
        ->and(OrderResource::getGlobalSearchResults('L-4'))->toHaveCount(1);
});

it('V96 starts an export with the job that checks its rows again, carrying the view permission', function (): void {
    Bus::fake();
    ExportAction::configureUsing(static fn (ExportAction $action) => $action->formats([ExportFormat::Csv]));
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->callAction(TestAction::make('export')->table());

    Bus::assertChained([Bus::chainedBatch(static function (PendingBatch $batch): bool {
        $job = $batch->jobs->first();

        return $job instanceof AuthorizedPrepareCsvExport
            && (new ReflectionProperty($job, 'options'))->getValue($job)[AuthorizedExportCsv::OPTION]
                === ['panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class];
    }), ExportCompletion::class]);

    expect((new AuthorizedPrepareCsvExport(exportOf(User::query()->findOrFail(1)), EloquentSerializeFacade::serialize(Order::query()), ['number' => 'Number']))->getExportCsvJob())
        ->toBe(AuthorizedExportCsv::class);
});

it('V96 refuses an export whose job would not check its rows', function (): void {
    Bus::fake();
    ExportAction::configureUsing(static fn (ExportAction $action) => $action->formats([ExportFormat::Csv]));
    ExportAction::configureUsing(static fn (ExportAction $action) => $action->job(UncheckedPrepareCsvExport::class));
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->callAction(TestAction::make('export')->table())->assertNotified();

    Bus::assertNothingBatched();
});

it('V96 writes only the rows that are still visible when the export job runs', function (): void {
    Storage::fake('local');
    $member = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = exportOf($member);
    $job = static fn (): AuthorizedExportCsv => new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Order::query()), [1, 2, 3, 4], 1, ['number' => 'Number'],
        [AuthorizedExportCsv::OPTION => ['panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class]]);
    $run = static function (AuthorizedExportCsv $job) use ($export): string {
        // The job runs on a queue, with the guard panel of the request that started the export as the hint.
        app(PanelContext::class)->carry(app(PanelRegistry::class)->get('admin'), static fn () => Queue::connection('sync')->push($job));

        return Storage::disk('local')->get('filament_exports/'.$export->getKey().'/0000000000000001.csv') ?? '';
    };

    expect($run($job()))->toBe("A-1\nA-2\nL-4\n");

    GateWorld::revoke('orders.view');
    expect($run($job()))->toBe('');
});

it('V96 writes no row without the guard panel of the export or with another one', function (): void {
    Storage::fake('local');
    $export = exportOf(GateWorld::grant(['orders.view_any', 'orders.view']));
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Order::query()), [1, 2], 1, ['number' => 'Number'],
        [AuthorizedExportCsv::OPTION => ['panel' => 'backoffice', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class]]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    $job->handle();

    expect(Storage::disk('local')->get('filament_exports/'.$export->getKey().'/0000000000000001.csv'))->toBe('')
        ->and(Context::getHidden(PanelContext::KEY))->toBeNull();
});

function exportOf(User $user): Export
{
    $export = new Export;
    $export->user()->associate($user);
    $export->exporter = OrderExporter::class;
    $export->total_rows = 4;
    $export->file_disk = 'local';
    $export->save();

    return $export;
}

final class UncheckedPrepareCsvExport extends PrepareCsvExport {}

it('refuses a custom row action without its permission even when the record is visible', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    FilamentFixture::$recordActions = [Action::make('destroy')->action(static fn (Order $record) => $record->delete())];

    Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('destroy')->table(1))
        ->call('mountAction', 'destroy', [], ['table' => true, 'recordKey' => '1']);

    expect(Order::query()->find(1))->not->toBeNull();
});

it('authorizes a custom bulk action for every record before invoking its callback', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    FilamentFixture::$toolbarActions = [
        BulkAction::make('delete')->requiresConfirmation()->action(static fn (Collection $records) => $records->each->delete()),
    ];

    Livewire::test(ListOrders::class)->selectTableRecords([1, 4])
        ->mountAction(TestAction::make('delete')->table()->bulk())
        ->call('callMountedAction')->assertForbidden();

    expect(Order::query()->find(4))->not->toBeNull();
});

it('refuses a bulk callback that disables per-record authorization and fetches only keys', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    FilamentFixture::$toolbarActions = [
        BulkAction::make('delete')->requiresConfirmation()->fetchSelectedRecords(false)->authorizeIndividualRecords(false)
            ->action(static fn () => Order::query()->whereKey([1, 4])->delete()),
    ];

    Livewire::test(ListOrders::class)->selectTableRecords([1, 4])
        ->mountAction(TestAction::make('delete')->table()->bulk())
        ->call('callMountedAction')->assertForbidden();

    expect(Order::query()->find(1))->not->toBeNull()->and(Order::query()->find(4))->not->toBeNull();
});

it('refuses enabled inline editing on a guarded resource before it writes', function (string $column, string $name, mixed $value): void {
    FilamentFixture::$tableColumns = [$column::make($name)];
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $before = Order::query()->findOrFail(1)->getAttribute($name);

    Livewire::test(ListOrders::class)->call('updateTableColumnState', $name, '1', $value)->assertForbidden();

    expect(Order::query()->findOrFail(1)->getAttribute($name))->toBe($before);
})->with([
    'text' => [TextInputColumn::class, 'number', 'HACKED'],
    'toggle' => [ToggleColumn::class, 'locked', true],
    'checkbox' => [CheckboxColumn::class, 'locked', true],
]);

it('lets a disabled inline column keep the record untouched', function (): void {
    FilamentFixture::$tableColumns = [TextInputColumn::make('number')->disabled()];
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'HACKED');

    expect(Order::query()->findOrFail(1)->number)->toBe('A-1');
});

it('runs an explicitly authorized custom row action and still honors its denial', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']));
    FilamentFixture::$recordActions = [Action::make('destroy')
        ->authorize(static fn (Order $record) => FilamentGate::resource(OrderResource::class, 'delete', $record))
        ->action(static fn (Order $record) => $record->delete())];

    Livewire::test(ListOrders::class)->callAction(TestAction::make('destroy')->table(1));
    Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('destroy')->table(4));

    expect(Order::query()->find(1))->toBeNull()->and(Order::query()->find(4))->not->toBeNull();
});

it('runs a custom bulk callback when every selected record is authorized', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    FilamentFixture::$toolbarActions = [BulkAction::make('delete')
        ->action(static fn (Collection $records) => $records->each->delete())];

    Livewire::test(ListOrders::class)->selectTableRecords([1, 2])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Order::query()->pluck('id')->sort()->values()->all())->toBe([3, 4]);
});

it('refuses a raw column action closure that bypasses the action authorization surface', function (): void {
    FilamentFixture::$tableColumns = [TextColumn::make('number')->action(static fn (Order $record) => $record->delete())];
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->call('callTableColumnAction', 'number', '1')->assertForbidden();

    expect(Order::query()->find(1))->not->toBeNull();
});

it('keeps inline editing in a Filament panel without the plugin', function (): void {
    FilamentFixture::$tableColumns = [TextInputColumn::make('number')];
    GateWorld::serve(User::query()->findOrFail(1), 'plain');

    Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', '1', 'PLAIN');

    expect(Order::query()->findOrFail(1)->number)->toBe('PLAIN');
});
