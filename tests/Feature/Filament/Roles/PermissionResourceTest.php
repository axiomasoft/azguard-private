<?php

declare(strict_types=1);

use AzGuard\Events\PermissionCreated;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\PermissionResource;
use AzGuard\Filament\Resources\PermissionResource\Pages\ListPermissions;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * V76, V61: the permission editor lists every permission of a managed panel with dynamic permissions; a permission of
 * an enum is read-only, a policy-only one is a badge of its authority; a dynamic permission is created, renamed and
 * deleted with its grants, as the user who edits.
 */

const EDITS = ['azguard-permissions.view_any', 'azguard-permissions.view', 'azguard-permissions.create', 'azguard-permissions.update', 'azguard-permissions.delete'];

beforeEach(function (): void {
    GateWorld::prepare();
    EditorWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
});

/** @return array<string, array<string, mixed>> */
function permissionRows(mixed $component): array
{
    return $component->instance()->getTableRecords()->all();
}

it('offers only the managed panels that keep dynamic permissions', function (): void {
    EditorWorld::editor(EDITS);

    $component = Livewire::test(ListPermissions::class);

    expect(TargetSelector::current(PermissionResource::keepsDynamicPermissions(...))->panels())->toBe(['seller' => 'seller'])
        ->and(permissionRows($component))->toHaveKey('entry.enter');
});

it('V61 shows a permission of an enum read-only and a policy-only permission as a badge of the policy', function (): void {
    EditorWorld::editor(EDITS);

    $component = Livewire::test(ListPermissions::class)
        ->assertSee('decided by the policy')
        ->assertActionHidden(TestAction::make('edit')->table('entry.enter'))
        ->assertActionHidden(TestAction::make('delete')->table('entry.enter'))
        ->assertActionHidden(TestAction::make('edit')->table('archived-orders.view'))
        ->assertActionHidden(TestAction::make('delete')->table('archived-orders.view'));
    $columns = $component->instance()->getTable()->getColumns();

    expect(array_filter($columns, fn ($column): bool => $column instanceof CheckboxColumn || $column instanceof ToggleColumn))->toBe([])
        ->and(permissionRows($component)['archived-orders.view'])->toMatchArray(['authority' => 'policy', 'dynamic' => false])
        ->and(permissionRows($component)['archived-orders.view']['decided_by'])->toContain('ArchivedOrderPolicy')
        ->and(permissionRows($component)['entry.enter'])->toMatchArray(['authority' => 'grants', 'dynamic' => false, 'domain' => 'entry']);
});

it('V76 creates a dynamic permission, which is granted and decided, and deleting it takes its grants', function (): void {
    EditorWorld::editor(EDITS);
    $receiver = User::query()->findOrFail(2);

    Livewire::test(ListPermissions::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'reports.export', 'label' => 'Export reports', 'group' => 'Reports'])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');

    $created = collect(AzGuard::panel('seller')->permissions()->all())->first(fn ($p) => $p->key->local() === 'reports.export');
    expect($created?->dynamic)->toBeTrue()
        ->and($created?->label)->toBe('Export reports')
        ->and($created?->resourceGroup)->toBe('Reports');

    AzGuard::panel('seller')->for($receiver)->grantPermission('reports.export');
    expect(AzGuard::panel('seller')->for($receiver)->hasPermission('reports.export'))->toBeTrue();

    Livewire::test(ListPermissions::class)
        ->callAction(TestAction::make('edit')->table('reports.export'), ['label' => 'Export', 'group' => null])
        ->callAction(TestAction::make('delete')->table('reports.export'))
        ->assertNotified('Saved');

    expect(collect(AzGuard::panel('seller')->permissions()->all())->contains(fn ($p) => $p->key->local() === 'reports.export'))->toBeFalse()
        ->and(DB::table('azg_permission_grants')->where('permission', 'reports.export')->count())->toBe(0)
        ->and(fn () => AzGuard::panel('seller')->for($receiver)->hasPermission('reports.export'))->toThrow(UnknownPermissionException::class);
});

it('V76 renames and regroups a dynamic permission without touching its name', function (): void {
    EditorWorld::editor(EDITS);
    AzGuard::panel('seller')->permissions()->create('reports.export', 'Export reports');

    $component = Livewire::test(ListPermissions::class)
        ->callAction(TestAction::make('edit')->table('reports.export'), ['label' => 'Export', 'group' => 'Reports']);

    expect(permissionRows($component)['reports.export'])->toMatchArray(['label' => 'Export', 'group' => 'Reports', 'dynamic' => true]);
});

it('writes as the user who edits, so the change names the editor as its actor', function (): void {
    EditorWorld::editor(EDITS);
    $events = [];
    Event::listen(PermissionCreated::class, function (PermissionCreated $event) use (&$events): void {
        $events[] = $event;
    });

    Livewire::test(ListPermissions::class)->callAction(TestAction::make('create')->table(), ['name' => 'reports.export']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->actor)->toEqual(ActorRef::of('user', 1));
});

it('hides the writes without the permissions of the editor', function (): void {
    EditorWorld::editor(['azguard-permissions.view_any', 'azguard-permissions.view']);
    AzGuard::panel('seller')->permissions()->create('reports.export');

    Livewire::test(ListPermissions::class)
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table('reports.export'))
        ->assertActionHidden(TestAction::make('delete')->table('reports.export'));

    expect(collect(AzGuard::panel('seller')->permissions()->all())->contains(fn ($p) => $p->key->local() === 'reports.export'))->toBeTrue();
});

it('chooses the tenant of another panel in its directory and writes in that tenant only', function (): void {
    FilamentFixture::$manages = ['admin', 'seller', 'teams'];
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
    EditorWorld::editor(EDITS);

    $component = Livewire::test(ListPermissions::class)->set('tableFilters.target.panel', 'teams');

    expect(permissionRows($component))->toBe([]);

    $component->set('tableFilters.target.tenant', '8')
        ->callAction(TestAction::make('create')->table(), ['name' => 'boards.edit']);

    $names = fn (int $team): array => array_map(fn ($p) => $p->key->local(), AzGuard::panel('teams')->inTenant(TenantRef::of('team', $team))->permissions()->all());
    expect($names(8))->toContain('boards.edit')
        ->and($names(7))->not->toContain('boards.edit');

    $component->assertSet('tableFilters.target.panel', 'teams')
        ->assertSet('tableFilters.target.tenant', '8')
        ->callAction(TestAction::make('edit')->table('boards.edit'), ['label' => 'Edit boards', 'group' => null])
        ->assertSet('tableFilters.target.panel', 'teams')
        ->assertSet('tableFilters.target.tenant', '8')
        ->callAction(TestAction::make('delete')->table('boards.edit'))
        ->assertSet('tableFilters.target.panel', 'teams')
        ->assertSet('tableFilters.target.tenant', '8');

    expect($names(8))->not->toContain('boards.edit');

    $component->set('tableFilters.target.panel', 'seller')
        ->assertSet('tableFilters.target.tenant', null);
});

it('refuses a tenant that the directory of the panel does not know', function (): void {
    FilamentFixture::$manages = ['admin', 'seller', 'teams'];
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
    EditorWorld::editor(EDITS);

    Livewire::test(ListPermissions::class)
        ->set('tableFilters.target.panel', 'teams')
        ->set('tableFilters.target.tenant', '99')
        ->assertForbidden();
});

it('takes the tenant of the request in the guard panel and refuses a forged tenant B at tenant A', function (): void {
    FilamentFixture::$editors = ['permissions' => true];
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
    EditorWorld::teamEditor(EDITS);

    expect(TargetSelector::current()->choosesTenant('teams'))->toBeFalse();

    $component = Livewire::test(ListPermissions::class)
        ->set('tableFilters.target.panel', 'teams')
        ->callAction(TestAction::make('create')->table(), ['name' => 'boards.edit']);

    $names = fn (int $team): array => array_map(fn ($p) => $p->key->local(), AzGuard::panel('teams')->inTenant(TenantRef::of('team', $team))->permissions()->all());
    expect($names(7))->toContain('boards.edit');

    $component->set('tableFilters.target.tenant', '8')->assertForbidden();

    expect($names(8))->not->toContain('boards.edit');
});
