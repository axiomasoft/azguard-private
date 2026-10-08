<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Laravel\Queue\PanelContext;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Filament\BootsCrmFilament;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientCalled;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientCountWidget;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientExporter;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientResource;
use AzGuard\Tests\Fixtures\Crm\Filament\CrmFilamentUser;
use AzGuard\Tests\Fixtures\Crm\Filament\Pages\ListClients;
use AzGuard\Tests\Fixtures\Crm\Filament\Pages\ViewClient;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Filament\Exports\ExportSpy;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use Filament\Actions\Exports\ExportDispatcher;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * CRM through its Filament panel /crm/{organization} over the guard panel `crm`: admission with the tenant Filament
 * chose (R39), the boundaries of list, count, widget, global search, bulk action and export (R34), and the calling
 * workflow of a caller of project P1 (R40).
 */

uses(BootsCrmFilament::class);

beforeEach(function (): void {
    self::crmFilament();
});

/** User 4 as a caller of P1 in organization A who may open the client list of the organization. */
function filamentCaller(): void
{
    DB::table('organization_user')->insertOrIgnore([['organization_id' => 1, 'user_id' => 4], ['organization_id' => 2, 'user_id' => 4]]);
    World::assign('caller', 4, 1);
    World::assign('clients.view_any', 4, 1, kind: 'permission', scope: World::scope(1));
}

it('R39 refuses the Filament panel without a role even with a direct grant and a policy allow of the entry', function (): void {
    self::crmFilament(static fn (PanelBuilder $panel) => $panel->entry(EntryPermission::Enter));
    self::member(4);
    World::assign('clients.view', 4, 1, kind: 'permission');
    World::assign('clients.view_any', 4, 1, kind: 'permission', scope: World::scope(1));
    $this->actingAs(CrmFilamentUser::query()->findOrFail(4));

    $this->get('/crm/1/clients')->assertForbidden();
    expect(AzGuard::check(User::query()->findOrFail(4), 'crm:clients.view', Client::query()->findOrFail(1)))->toBeTrue();
});

it('R39 lets a caller of P1 into organization A only, and opens there only the clients of its project', function (): void {
    filamentCaller();
    $this->actingAs(CrmFilamentUser::query()->findOrFail(4));

    $this->get('/crm/1/clients')->assertOk();
    $this->get('/crm/2/clients')->assertForbidden();
    $this->get('/crm/1/clients/1')->assertOk();
    $this->get('/crm/1/clients/3')->assertNotFound();
    $this->get('/crm/1/clients/6')->assertNotFound();
    $this->get('/crm/1/clients/5')->assertNotFound();
});

it('R34 shows in the list, its count, the widget and global search only the clients of the own project and tenant', function (): void {
    filamentCaller();
    World::assign('widgets.client-count', 4, 1, kind: 'permission', scope: World::scope(1));
    $this->serveCrm(4);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords(Client::query()->findMany([1, 2]))
        ->assertCanNotSeeTableRecords(Client::query()->findMany([3, 4, 5, 6]))
        ->assertCountTableRecords(2);
    Livewire::test(ClientCountWidget::class)->assertSee('Count: 2');

    expect(ClientResource::getGlobalSearchResults('3'))->toHaveCount(0)
        ->and(ClientResource::getGlobalSearchResults('1'))->toHaveCount(1)
        ->and(ClientResource::getEloquentQuery()->pluck('id')->sort()->values()->all())->toBe([1, 2]);
});

it('R34 deletes in bulk only own clients: a foreign id in the selection is not even found', function (): void {
    filamentCaller();
    World::assign('clients.delete_any', 4, 1, kind: 'permission', scope: World::scope(1));
    World::assign('clients.delete', 4, 1, kind: 'permission');
    $this->serveCrm(4);
    DB::table('clients')->insert(['id' => 7, 'organization_id' => 1, 'project_id' => 1, 'do_not_call' => false, 'owner_user_id' => 1]);

    Livewire::test(ListClients::class)
        ->selectTableRecords([7, 3, 6])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Client::query()->pluck('id')->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6]);
});

it('R34 exports only the clients the caller may still view, in its organization', function (): void {
    filamentCaller();
    $spy = new ExportSpy;
    app()->instance(ExportDispatcher::class, $spy);
    $this->serveCrm(4);

    Livewire::test(ListClients::class)->callAction(TestAction::make('export')->table());

    $options = $spy->dispatched[0]['options'][AuthorizedExportCsv::OPTION];
    expect($spy->dispatched[0]['job'])->toBe(AuthorizedPrepareCsvExport::class)
        ->and($options)->toBe(['panel' => 'crm', 'permission' => 'clients.view', 'tenant' => ['type' => 'crm.organization', 'id' => '1'], 'model' => Client::class]);

    Storage::fake('local');
    $export = new Export;
    $export->user()->associate(CrmFilamentUser::query()->findOrFail(4));
    $export->exporter = ClientExporter::class;
    $export->total_rows = 6;
    $export->file_disk = 'local';
    $export->save();
    $job = new AuthorizedExportCsv($export, EloquentSerializeFacade::serialize(Client::query()), [1, 2, 3, 4, 5, 6], 1, ['id' => 'Id'], [AuthorizedExportCsv::OPTION => $options]);
    app(PanelContext::class)->carry(app(PanelRegistry::class)->get('crm'), static fn () => Queue::connection('sync')->push($job));

    expect(Storage::disk('local')->get('filament_exports/'.$export->getKey().'/0000000000000001.csv'))->toBe("1\n2\n");
});

it('R40 lets a caller open the list and the card and record a call to an own client, and refuses the call where the client must not be called', function (): void {
    filamentCaller();
    Event::fake([ClientCalled::class]);
    $this->serveCrm(4);

    Livewire::test(ListClients::class)->assertCanSeeTableRecords(Client::query()->findMany([1, 2]));
    Livewire::test(ViewClient::class, ['record' => 1])->callAction('call');

    expect(DB::table('client_calls')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe([['client_id' => 1, 'user_id' => 4]]);
    Event::assertDispatchedTimes(ClientCalled::class, 1);

    // The hidden action is refused by the server too when the payload asks for it.
    Livewire::test(ViewClient::class, ['record' => 2])->assertActionHidden('call')->call('mountAction', 'call')->call('callMountedAction');

    expect(DB::table('client_calls')->count())->toBe(1);
    Event::assertDispatchedTimes(ClientCalled::class, 1);
});

it('R40 does not open the card of a client of another project, so no call is recorded for it', function (): void {
    filamentCaller();
    Event::fake([ClientCalled::class]);
    $this->serveCrm(4);

    Livewire::test(ViewClient::class, ['record' => 3])->assertNotFound();
    Livewire::test(ViewClient::class, ['record' => 5])->assertNotFound();

    expect(DB::table('client_calls')->count())->toBe(0);
    Event::assertNotDispatched(ClientCalled::class);
});
