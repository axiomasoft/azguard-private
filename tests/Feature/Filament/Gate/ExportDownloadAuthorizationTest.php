<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Filament\Internal\Exports\ChecksPrepareCsvExport;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Reads;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Tests\Fixtures\Filament\Exports\OrderExporter;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    Storage::fake('local');
});

function authorizationExport(User $user): Export
{
    $export = new Export;
    $export->user()->associate($user);
    $export->exporter = OrderExporter::class;
    $export->total_rows = 1;
    $export->file_disk = 'local';
    $export->save();

    return $export;
}

it('refuses an export action invoked without view any and creates no export', function (): void {
    Bus::fake();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $page = Livewire::test(ListOrders::class);
    GateWorld::revoke('orders.view_any');

    $page->call('mountAction', 'export', [], ['table' => true]);

    expect(Export::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('refuses an open export after record view is revoked and creates no export', function (): void {
    Bus::fake();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $page = Livewire::test(ListOrders::class)->call('mountAction', 'export', [], ['table' => true]);
    GateWorld::revoke('orders.view');

    $page->call('callMountedAction');

    expect(Export::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('refuses a custom preparation subclass that could bypass the checked preparation job', function (): void {
    Bus::fake();
    ExportAction::configureUsing(static fn (ExportAction $action) => $action->job(UncheckedAuthorizationPrepare::class));
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)->callAction(TestAction::make('export')->table())->assertNotified();

    expect(Export::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('does not authorize a custom callback named export with view permissions', function (bool $bulk): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $action = $bulk ? BulkAction::make('export') : Action::make('export');
    FilamentFixture::$toolbarActions = [$action->action(static function (): void {
        Order::query()->whereKey(1)->delete();
    })];

    $page = Livewire::test(ListOrders::class);

    if ($bulk) {
        $page->set('selectedTableRecords', ['1']);
    }
    $page->call('mountAction', 'export', [], ['table' => true, 'bulk' => $bulk]);

    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
})->with(['action' => false, 'bulk action' => true]);

it('requires primary reads and the custom export permission for an ordinary callback named export', function (bool $bulk, bool $primary): void {
    FilamentFixture::$reads = $primary ? Reads::Primary : Reads::Default;
    FilamentFixture::$guardPermissions[] = CustomExportCallbackPermission::class;
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.export', 'orders.export_any']));
    $action = $bulk ? BulkAction::make('export') : Action::make('export');
    FilamentFixture::$toolbarActions = [$action->action(static function (): void {
        Order::query()->whereKey(1)->delete();
    })];

    $page = Livewire::test(ListOrders::class);

    if ($bulk) {
        $page->set('selectedTableRecords', ['1']);
    }
    $page->call('mountAction', 'export', [], ['table' => true, 'bulk' => $bulk]);

    expect(Order::query()->whereKey(1)->exists())->toBe(! $primary);
})->with([false, true])->with([false, true]);

it('keeps checked export authorization when a genuine export action is renamed', function (bool $bulk): void {
    Bus::fake();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $action = $bulk ? ExportBulkAction::make('download_rows') : ExportAction::make('download_rows');
    FilamentFixture::$toolbarActions = [$action->exporter(OrderExporter::class)];

    $page = Livewire::test(ListOrders::class);

    if ($bulk) {
        $page->set('selectedTableRecords', ['1']);
    }
    $page->callAction(TestAction::make('download_rows')->table()->bulk($bulk));

    expect(Export::query()->count())->toBe(1);
    Bus::assertDispatched(ChainedBatch::class, static fn (ChainedBatch $job): bool => $job->jobs->first() instanceof ChecksPrepareCsvExport);
})->with(['action' => false, 'bulk action' => true]);

it('refuses an owner download of a legacy export when its authorization cannot be reconstructed', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', 'private');
    GateWorld::serve($user);
    GateWorld::revoke('orders.view');

    $this->actingAs($user)->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))->assertForbidden();
});

it('refuses a legacy file even when the current panel no longer enforces', function (): void {
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    Storage::fake('local');
    $user = User::query()->findOrFail(1);
    $export = authorizationExport($user);
    Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', 'private');
    GateWorld::serve($user, 'plain');

    $this->actingAs($user)->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))->assertForbidden();
});

it('does not create a chunk after revocation or malformed tenant authority', function (bool $revoked): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $authority = ['panel' => 'admin', 'permission' => 'orders.view', 'tenant' => $revoked ? null : ['type' => 'team'], 'model' => Order::class];
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Order::query()), [1], 1, ['number' => 'Number'], [AuthorizedExportCsv::OPTION => $authority]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    if ($revoked) {
        GateWorld::revoke('orders.view');
    }
    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/0000000000000001.csv');
})->with(['revoked' => true, 'malformed tenant' => false]);

it('does not write export headers when the job starts after view permission was revoked', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new ChecksPrepareCsvExport($export, EloquentSerializeFacade::serialize(Order::query()), ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));
    GateWorld::revoke('orders.view');

    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/headers.csv');
});

it('prepares an authorized export and schedules checked chunks', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new ChecksPrepareCsvExport($export, EloquentSerializeFacade::serialize(Order::query()), ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]], records: [1]);
    [, $batch] = $job->withFakeBatch();
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    $job->handle();

    Storage::disk('local')->assertExists($export->getFileDirectory().'/headers.csv');
    expect($batch->added)->toHaveCount(1)->and($batch->added[0])->toBeInstanceOf(AuthorizedExportCsv::class);
});

it('does not prepare an export for hidden selected keys when another query row is authorized', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new ChecksPrepareCsvExport($export, EloquentSerializeFacade::serialize(Order::query()), ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]], records: [3]);
    [, $batch] = $job->withFakeBatch();
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/headers.csv');
    expect($batch->added)->toBe([]);
});

it('does not create a chunk when the selected record is no longer visible', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Order::query()), [1], 1, ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));
    Order::query()->whereKey(1)->update(['secret' => true]);

    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/0000000000000001.csv');
});

it('refuses export preparation and chunks when authority reads can use a replica', function (): void {
    FilamentFixture::$reads = Reads::Default;
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    Storage::fake('local');
    $user = GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']);
    GateWorld::serve($user);
    $export = authorizationExport($user);
    $options = [AuthorizedExportCsv::OPTION => ['panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class]];
    $query = EloquentSerializeFacade::serialize(Order::query());
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    (new ChecksPrepareCsvExport($export, $query, ['number' => 'Number'], $options))->handle();
    (new AuthorizedExportCsv($export, $query, [1], 1, ['number' => 'Number'], $options))->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/headers.csv');
    Storage::disk('local')->assertMissing($export->getFileDirectory().'/0000000000000001.csv');
    expect(FilamentGate::resource(OrderResource::class, 'delete', Order::query()->findOrFail(1))->denied())->toBeTrue();
});

it('does not create a chunk for a deleted export actor', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Order::query()), [1], 1, ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));
    $user->delete();

    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/0000000000000001.csv');
});

it('refuses a serialized query for a different model even when its key matches an authorized record', function (): void {
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    $export = authorizationExport($user);
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(User::query()), [1], 1, ['number' => 'Number'], [AuthorizedExportCsv::OPTION => [
        'panel' => 'admin', 'permission' => 'orders.view', 'tenant' => null, 'model' => Order::class,
    ]]);
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('admin'));

    $job->handle();

    Storage::disk('local')->assertMissing($export->getFileDirectory().'/0000000000000001.csv');
});

final class UncheckedAuthorizationPrepare extends AuthorizedPrepareCsvExport {}

#[RequiresGrant]
enum CustomExportCallbackPermission: string
{
    case Export = 'orders.export';
    case ExportAny = 'orders.export_any';
}
