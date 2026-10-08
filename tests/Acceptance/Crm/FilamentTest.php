<?php

declare(strict_types=1);

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use AzGuard\Laravel\Queue\PanelContext;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Filament\BootsCrmFilament;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientCalled;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientCountWidget;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientExporter;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientResource;
use AzGuard\Tests\Fixtures\Crm\Filament\CrmEditorPermission;
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
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * CRM through its Filament panel /crm/{organization} over the guard panel `crm`: admission with the tenant Filament
 * chose (R39), the boundaries of list, count, widget, global search, bulk action and export (R34), the calling
 * workflow of a caller of project P1 (R40), and the grant editors over the managed panel `backoffice`: contexts
 * offered for the target subject (R36), ids of organization B refused in a form of A (R37), stale and inactive grants
 * listed and revoked (R28).
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

/** User 4 as the caller of P1 who also edits the grants of the CRM in organization A. */
function filamentGrantEditor(): void
{
    filamentCaller();

    foreach (CrmEditorPermission::cases() as $permission) {
        World::assign($permission->value, 4, 1, kind: 'permission', scope: World::scope(1));
    }
}

/** The options a select of the open action form finds for a term. */
function crmFormSearch(Testable $component, string $field, string $term = ''): array
{
    $page = $component->instance();
    $select = $page->getSchema((string) $page->getMountedActionSchemaName())
        ?->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === $field, withHidden: true);

    return $select instanceof Select ? array_keys($select->getSearchResults($term)) : throw new RuntimeException('No select '.$field.'.');
}

/** The contexts the role grant form offers in a tenant of the backoffice panel for a target and a role. */
function crmOfferedContexts(int $tenant, int $user, string $role): array
{
    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'backoffice', 'tenant' => (string) $tenant, 'subject' => 'crm.user:'.$user, 'key' => $role, 'context_type' => 'crm.project']);

    return crmFormSearch($component, 'context');
}

/** @return list<array<string, mixed>> the stored role grants of the backoffice panel */
function backofficeGrants(): array
{
    return DB::table('azg_role_grants')->where('panel', 'backoffice')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
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

it('R36 offers in the grant form the contexts of the target seller or analyst, not of the editor, with the limit after eligibility', function (): void {
    filamentGrantEditor();
    $this->serveCrm(4);

    // Анна lives in city 1, Борис in city 2; the editor (user 4) lives in city 1. Project 3 is inactive.
    // Project ids are integer keys of the options.
    expect(crmOfferedContexts(1, 1, 'seller'))->toBe([1, 5])
        ->and(crmOfferedContexts(1, 2, 'seller'))->toBe([2])
        ->and(crmOfferedContexts(1, 1, 'analyst'))->toBe([1, 2, 5])
        ->and(crmOfferedContexts(2, 1, 'analyst'))->toBe([4])
        ->and(crmOfferedContexts(2, 2, 'seller'))->toBe([]);
});

it('R36 offers no context before the target subject is chosen', function (): void {
    filamentGrantEditor();
    $this->serveCrm(4);
    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'backoffice', 'tenant' => '1', 'key' => 'seller', 'context_type' => 'crm.project']);

    expect(crmFormSearch($component, 'context'))->toBe([]);
});

it('R36 grants a role in a context offered for the target, with the fields of the schema', function (): void {
    filamentGrantEditor();
    $this->serveCrm(4);

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'backoffice', 'tenant' => '1', 'subject' => 'crm.user:2', 'key' => 'seller', 'context_type' => 'crm.project', 'context' => '2',
            'fields' => ['region' => 'R1', 'eligible' => true],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');

    $grants = backofficeGrants();

    expect(array_map(static fn (array $row): array => [$row['tenant_key'], $row['subject_id'], $row['role'], $row['context_key'], $row['actor_id']], $grants))
        ->toBe([['crm.organization:1', '2', 'seller', 'crm.project:2', '4']])
        ->and(json_decode((string) $grants[0]['meta'], true))->toMatchArray(['region' => 'R1', 'eligible' => true]);
});

it('R37 refuses a project, a subject or a grant of organization B in the editor of organization A and writes nothing', function (): void {
    filamentGrantEditor();
    World::assign('analyst', 1, 4, 2, panel: 'backoffice');
    $foreign = 'role:'.DB::table('azg_role_grants')->where('panel', 'backoffice')->where('tenant_key', 'crm.organization:2')->value('id');
    $this->serveCrm(4);
    $before = backofficeGrants();

    // Project P4 belongs to B: the form of A neither offers nor accepts it.
    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'backoffice', 'tenant' => '1', 'subject' => 'crm.user:1', 'key' => 'analyst', 'context_type' => 'crm.project', 'context' => '4',
        ])
        ->assertHasActionErrors(['context']);

    // Борис is not a member of B: the writer refuses the grant in B.
    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'backoffice', 'tenant' => '2', 'subject' => 'crm.user:2', 'key' => 'analyst', 'context_type' => 'crm.project', 'context' => '4',
        ])
        ->assertNotified('The grant was not saved');

    // A grant id of B in a bulk of A refuses the whole revocation.
    World::assign('analyst', 1, 1, panel: 'backoffice');
    $own = 'role:'.DB::table('azg_role_grants')->where('panel', 'backoffice')->where('tenant_key', 'crm.organization:1')->value('id');
    $before = backofficeGrants();

    Livewire::test(ListRoleGrants::class)
        ->filterTable('grants', ['panel' => 'backoffice', 'tenant' => '1'])
        ->set('selectedTableRecords', [$own, $foreign])
        ->callAction(TestAction::make('revoke')->table()->bulk())
        ->assertNotified('The grant was not saved');

    expect(backofficeGrants())->toBe($before);
});

it('R37 clears the subject, the role, the context and the fields when the organization of the form changes', function (): void {
    filamentGrantEditor();
    $this->serveCrm(4);

    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'backoffice', 'tenant' => '1', 'subject' => 'crm.user:1', 'key' => 'analyst', 'context_type' => 'crm.project', 'context' => '1',
            'fields' => ['region' => 'R1']])
        ->fillForm(['tenant' => '2']);

    expect($component->get('mountedActions.0.data'))->toMatchArray([
        'tenant' => '2', 'subject' => null, 'key' => null, 'context_type' => null, 'context' => null, 'fields' => [],
    ]);
});

it('R28 lists a grant in an inactive project and an expired one and revokes them without runtime eligibility', function (): void {
    filamentGrantEditor();
    World::assign('seller', 1, 3, panel: 'backoffice');
    $inactive = 'role:'.DB::table('azg_role_grants')->where('panel', 'backoffice')->where('context_key', 'crm.project:3')->value('id');
    World::assign('analyst', 1, 1, panel: 'backoffice');
    $expired = (int) DB::table('azg_role_grants')->where('panel', 'backoffice')->where('context_key', 'crm.project:1')->value('id');
    DB::table('azg_role_grants')->where('id', $expired)->update(['expires_at' => '2020-01-01 00:00:00']);
    $this->serveCrm(4);

    $component = Livewire::test(ListRoleGrants::class)->filterTable('grants', ['panel' => 'backoffice', 'tenant' => '1', 'state' => 'any']);
    $rows = array_keys(array_filter($component->instance()->getTableRecords()->all(), static fn (array $row): bool => $row['id'] !== "\0next"));

    expect($rows)->toBe([$inactive, 'role:'.$expired]);

    $component->callAction(TestAction::make('revoke')->table($inactive))->assertNotified('Saved')
        ->callAction(TestAction::make('revoke')->table('role:'.$expired))->assertNotified('Saved');

    expect(backofficeGrants())->toBe([]);
});
