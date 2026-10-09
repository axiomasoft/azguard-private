<?php

declare(strict_types=1);

use AzGuard\Changes\GrantDetails;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Editors\GrantEditor;
use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\GrantWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The role grant editor: a table of the stored role grants of one managed panel and tenant over the pages of the grant
 * manager, a form that grants a role with every value checked again on the server, the edit of a row against the
 * fingerprint the form was filled from, revocation of one row or of the selection in one change.
 */

beforeEach(function (): void {
    GrantWorld::prepare();
    $this->bootFilament();
    GrantWorld::seed();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The search results of a select of the open action form. */
function grantFormSearch(Testable $component, string $field, string $term = ''): array
{
    return grantFormSelect($component, $field)->getSearchResults($term);
}

function grantFormSelect(Testable $component, string $field): Select
{
    $page = $component->instance();
    $select = $page->getSchema((string) $page->getMountedActionSchemaName())
        ?->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === $field, withHidden: true);

    return $select instanceof Select ? $select : throw new RuntimeException('No select '.$field.' in the open form.');
}

/** @return array<string, array<string, mixed>> the rows of the table without the marker of a next page */
function grantRows(Testable $component): array
{
    return array_filter($component->instance()->getTableRecords()->all(), fn (array $row): bool => $row['id'] !== "\0next");
}

/** @return array<string, mixed> the state of the open action form */
function grantFormState(Testable $component): array
{
    return $component->get('mountedActions.0.data');
}

/** The role grants of user 2 in team 7 or 8 of the teams panel, for a table of team 7. */
function teamGrants(): array
{
    $receiver = User::query()->findOrFail(2);
    AzGuard::panel('teams')->inTenant(TenantRef::of('team', 7))->for($receiver)->grantRole('member');
    AzGuard::panel('teams')->inTenant(TenantRef::of('team', 8))->for($receiver)->grantRole('member');

    return [GrantWorld::memberGrant(2, 'teams', 7), GrantWorld::memberGrant(2, 'teams', 8)];
}

it('V63 hides a panel without a database source from the grant editors, also when the plugin manages every panel', function (): void {
    GrantWorld::prepare(manages: null);
    $this->bootFilament();
    GrantWorld::seed();
    GrantWorld::editor();

    expect(array_keys(TargetSelector::current()->panels()))->toBe(['admin', 'backoffice', 'seller', 'teams']);

    Livewire::test(ListRoleGrants::class)
        ->assertSuccessful()
        ->filterTable('grants', ['panel' => 'readonly'])
        ->assertForbidden();
});

it('lists the stored role grants of the panel with the labels of the directories and the schema', function (): void {
    GrantWorld::editor();

    $rows = grantRows(Livewire::test(ListRoleGrants::class)->assertSuccessful()->assertSee('Member'));

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows)[0])->toMatchArray([
            'subject' => 'user:1', 'subject_label' => 'Member', 'key' => 'member', 'key_label' => 'member',
            'context_label' => 'Whole tenant', 'origin' => 'manual', 'until' => null,
        ]);
});

it('refuses the editor to a user without its permissions and hides what the user may not do', function (): void {
    GrantWorld::editor(['azguard-role-grants.view_any', 'azguard-role-grants.view']);

    Livewire::test(ListRoleGrants::class)
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('revoke')->table(GrantWorld::memberGrant(1)))
        ->assertActionHidden(TestAction::make('edit')->table(GrantWorld::memberGrant(1)))
        ->assertActionVisible(TestAction::make('why')->table());

    GrantWorld::editor(['azguard-permission-grants.view_any']);
    AzGuard::panel('admin')->for(User::query()->findOrFail(1))->revokePermission('azguard-role-grants.view_any');

    Livewire::test(ListRoleGrants::class)->assertForbidden();
});

it('V26 searches a subject among 10 000 users with one query of at most 50 rows and labels it through the morph map', function (): void {
    GrantWorld::editor();
    foreach (array_chunk(range(3, 10002), 500) as $ids) {
        User::query()->insert(array_map(fn (int $id): array => ['id' => $id, 'name' => 'User '.$id], $ids));
    }
    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'admin']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/from\s+"users"/i', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });

    $all = grantFormSearch($component, 'subject');
    $searchQueries = $queries;
    $queries = [];
    $found = grantFormSearch($component, 'subject', 'User 5000');

    expect($all)->toHaveCount(50)
        ->and($searchQueries)->toHaveCount(1)
        ->and($searchQueries[0])->toMatch('/limit 50/i')
        ->and($queries)->toHaveCount(1)
        ->and($found)->toBe(['user:5000' => 'User 5000']);

    $component->fillForm(['subject' => 'user:5000']);

    expect(grantFormSelect($component, 'subject')->getOptionLabel())->toBe('User 5000');
});

it('grants a role through the form as the user who edits, with the expiry, in the target panel only', function (): void {
    GrantWorld::editor();

    $component = Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-', 'until' => '2030-01-01 10:00:00',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');
    $row = collect(grantRows($component))->firstWhere('subject', 'user:2');

    expect($row)->toMatchArray(['key' => 'member', 'subject_label' => 'Outsider', 'granted_by' => 'Member'])
        ->and($row['until'])->toStartWith('2030-01-01T10:00:00')
        ->and(AzGuard::panel('admin')->grants()->find($row['id'])?->actor)->toEqual(ActorRef::of('user', 1))
        ->and(DB::table('azg_role_grants')->where('panel', '!=', 'admin')->count())->toBe(0);
});

it('R25 grants only the role of the form: keys of a role definition in the payload change no role, no catalog and no permission', function (): void {
    GrantWorld::editor();
    $roles = static fn (): array => array_map(static fn ($role): string => $role->key->key().':'.$role->class, AzGuard::panel('admin')->roles()->all());
    $before = $roles();
    $permissionGrants = DB::table('azg_permission_grants')->count();

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-',
            'class_name' => 'App\\Roles\\Forged', 'definition' => 'forged', 'permissions' => ['*'], 'superAdmin' => true,
        ])
        ->assertNotified('Saved');

    expect($roles())->toBe($before)
        ->and(DB::table('azg_role_grants')->where('subject_id', '2')->count())->toBe(1)
        ->and(AzGuard::panel('admin')->for(User::query()->findOrFail(2))->hasRole('member'))->toBeTrue()
        ->and(DB::table('azg_permission_grants')->count())->toBe($permissionGrants);
});

it('V96 clears the subject, the role, the context and the fields when the panel or the tenant of the form changes', function (): void {
    GrantWorld::editor();

    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'teams', 'tenant' => '7', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-'])
        ->fillForm(['tenant' => '8']);

    expect(grantFormState($component))->toMatchArray(['panel' => 'teams', 'tenant' => '8', 'subject' => null, 'key' => null, 'context_type' => null, 'context' => null, 'fields' => []]);

    $component->fillForm(['subject' => 'user:2', 'key' => 'member'])->fillForm(['panel' => 'admin']);

    expect(grantFormState($component))->toMatchArray(['panel' => 'admin', 'tenant' => null, 'subject' => null, 'key' => null, 'context_type' => null]);
});

it('V96 grants in the tenant chosen in the form, which the directory of the panel checks', function (): void {
    GrantWorld::editor();

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), ['panel' => 'teams', 'tenant' => '8', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-'])
        ->assertHasNoActionErrors();

    expect(DB::table('azg_role_grants')->where('panel', 'teams')->pluck('tenant_key')->all())->toBe(['team:8']);

    $before = DB::table('azg_role_grants')->count();

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), ['panel' => 'teams', 'tenant' => '99', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-'])
        ->assertHasActionErrors(['tenant']);

    expect(DB::table('azg_role_grants')->count())->toBe($before);
});

it('V96 refuses a forged subject, role or context type in the payload of the form and writes nothing', function (): void {
    GrantWorld::editor();
    $rows = DB::table('azg_role_grants')->count();

    foreach ([
        'subject' => ['panel' => 'admin', 'subject' => 'user:999', 'key' => 'member', 'context_type' => '-'],
        'subject ' => ['panel' => 'admin', 'subject' => 'team:7', 'key' => 'member', 'context_type' => '-'],
        'key' => ['panel' => 'seller', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-'],
        'context_type' => ['panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => 'crm.project'],
    ] as $field => $data) {
        Livewire::test(ListRoleGrants::class)
            ->callAction(TestAction::make('create')->table(), $data)
            ->assertHasActionErrors([trim($field)]);
    }

    expect(DB::table('azg_role_grants')->count())->toBe($rows);
});

it('V96 checks every value again on the server even when the form is skipped', function (): void {
    $user = GrantWorld::editor();
    $editor = GrantEditor::of(TargetSelector::current()->access('admin'), 'role');
    $rows = DB::table('azg_role_grants')->count();

    expect(fn () => $editor->grant(['subject' => 'user:999', 'key' => 'member'], $user))->toThrow(ValidationException::class)
        ->and(fn () => $editor->grant(['subject' => 'user:2', 'key' => 'admin'], $user))->toThrow(ValidationException::class)
        ->and(fn () => $editor->grant(['subject' => 'user:2', 'key' => 'member', 'context_type' => 'team'], $user))->toThrow(ValidationException::class)
        ->and(fn () => TargetSelector::current()->access('readonly'))->toThrow(HttpException::class)
        ->and(fn () => TargetSelector::current()->access('teams', '99'))->toThrow(HttpException::class)
        ->and(DB::table('azg_role_grants')->count())->toBe($rows);
});

it('V96 R37 refuses the edit and the revocation of a grant of another tenant and a bulk with one such id revokes nothing', function (): void {
    GrantWorld::editor();
    [$seven, $eight] = teamGrants();
    $component = Livewire::test(ListRoleGrants::class)->filterTable('grants', ['panel' => 'teams', 'tenant' => '7']);
    $rows = DB::table('azg_role_grants')->orderBy('id')->get()->all();

    expect(array_keys(grantRows($component)))->toBe([$seven]);

    $component->set('selectedTableRecords', [$seven, $eight])
        ->callAction(TestAction::make('revoke')->table()->bulk())
        ->assertNotified('The grant was not saved');

    expect(DB::table('azg_role_grants')->orderBy('id')->get()->all())->toEqual($rows);

    $editor = GrantEditor::of(TargetSelector::current()->access('teams', '7'), 'role');

    expect(fn () => $editor->update($eight, null, [], User::query()->findOrFail(1)))->toThrow(ValidationException::class)
        ->and(fn () => $editor->revoke([$eight], User::query()->findOrFail(1)))->toThrow(StaleSelectionException::class)
        ->and(fn () => $editor->revoke(['permission:1'], User::query()->findOrFail(1)))->toThrow(ValidationException::class)
        ->and(DB::table('azg_role_grants')->orderBy('id')->get()->all())->toEqual($rows);

    $component->set('selectedTableRecords', [$seven])
        ->callAction(TestAction::make('revoke')->table()->bulk())
        ->assertNotified('Saved');

    expect(AzGuard::panel('teams')->inTenant(TenantRef::of('team', 7))->grants()->find($seven))->toBeNull()
        ->and(AzGuard::panel('teams')->inTenant(TenantRef::of('team', 8))->grants()->find($eight))->not->toBeNull();
});

it('revokes the selected grants of the page in one change', function (): void {
    GrantWorld::editor();
    $receiver = User::query()->findOrFail(2);
    AzGuard::panel('admin')->for($receiver)->grantRole('member');
    $ids = [GrantWorld::memberGrant(1), GrantWorld::memberGrant(2)];
    $version = DB::table('azg_panel_state')->where('panel', 'admin')->value('version');

    Livewire::test(ListRoleGrants::class)
        ->selectTableRecords($ids)
        ->callAction(TestAction::make('revoke')->table()->bulk())
        ->assertNotified('Saved');

    expect(DB::table('azg_role_grants')->where('panel', 'admin')->count())->toBe(0)
        ->and(DB::table('azg_panel_state')->where('panel', 'admin')->value('version'))->toBe($version + 1);
});

it('R24 refuses the edit of a row that changed after its form was filled and saves a fresh one', function (): void {
    GrantWorld::editor();
    $id = GrantWorld::memberGrant(1);
    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('edit')->table($id))
        ->fillForm(['until' => '2031-01-01 00:00:00']);
    $rows = DB::table('azg_role_grants')->get()->all();
    AzGuard::panel('admin')->grants()->update($id, new GrantDetails(new DateTimeImmutable('2032-01-01T00:00:00Z')));
    $changed = DB::table('azg_role_grants')->get()->all();

    $component->callMountedAction()->assertNotified('The grant was not saved');

    expect(DB::table('azg_role_grants')->get()->all())->toEqual($changed)->not->toEqual($rows)
        ->and(AzGuard::panel('admin')->grants()->find($id)?->until?->format('Y'))->toBe('2032');

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('edit')->table($id), ['until' => '2031-01-01 00:00:00'])
        ->assertNotified('Saved');

    expect(AzGuard::panel('admin')->grants()->find($id)?->until?->format('Y'))->toBe('2031');
});

it('R24 keeps the fingerprint of the open form on the server, out of reach of the payload', function (): void {
    GrantWorld::editor();
    $id = GrantWorld::memberGrant(1);
    $component = Livewire::test(ListRoleGrants::class)->mountAction(TestAction::make('edit')->table($id));

    expect($component->get('editing'))->toBe(['id' => $id, 'fingerprint' => AzGuard::panel('admin')->grants()->find($id)?->fingerprint])
        ->and(fn () => $component->set('editing', ['id' => $id, 'fingerprint' => 'forged']))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('grantCursors', [2 => 'forged']))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('R28 lists expired and orphaned grants for revocation and revokes them', function (): void {
    GrantWorld::editor();
    Carbon::setTestNow('2026-10-01T10:00:00Z');
    $receiver = User::query()->findOrFail(2);
    AzGuard::panel('admin')->for($receiver)->grantRole('member', until: new DateTimeImmutable('2026-10-02T00:00:00Z'));
    $expired = GrantWorld::memberGrant(2);
    Carbon::setTestNow('2026-10-05T10:00:00Z');
    $stored = (array) DB::table('azg_role_grants')->where('id', (int) substr($expired, 5))->first();
    DB::table('azg_role_grants')->insert([...array_diff_key($stored, ['id' => true]), 'role' => 'gone', 'expires_at' => null]);
    $orphaned = 'role:'.DB::table('azg_role_grants')->where('role', 'gone')->value('id');
    $component = Livewire::test(ListRoleGrants::class);

    expect(array_keys(grantRows($component)))->toBe([GrantWorld::memberGrant(1)])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'state' => 'expired']))))->toBe([$expired])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'state' => 'orphaned']))))->toBe([$orphaned]);

    $component->callAction(TestAction::make('revoke')->table($orphaned))->assertNotified('Saved')
        ->filterTable('grants', ['panel' => 'admin', 'state' => 'expired'])
        ->callAction(TestAction::make('revoke')->table($expired))->assertNotified('Saved');

    expect(DB::table('azg_role_grants')->where('subject_id', '2')->count())->toBe(0);
});

it('filters by role, subject, an expiry before a moment and who granted', function (): void {
    GrantWorld::editor();
    $receiver = User::query()->findOrFail(2);
    AzGuard::actingAs(User::query()->findOrFail(1), fn () => AzGuard::panel('admin')->for($receiver)->grantRole('member', until: new DateTimeImmutable('2027-01-01T00:00:00Z')));
    $mine = GrantWorld::memberGrant(2);
    $component = Livewire::test(ListRoleGrants::class);

    expect(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'subject' => 'user:2']))))->toBe([$mine])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'key' => 'member']))))->toHaveCount(2)
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'expires_before' => '2027-06-01 00:00:00']))))->toBe([$mine])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'expires_before' => '2026-12-01 00:00:00']))))->toBe([])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'granted_by' => 'user:1']))))->toBe([$mine])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'granted_by' => 'system']))))->toBe([GrantWorld::memberGrant(1)])
        ->and(array_keys(grantRows($component->filterTable('grants', ['panel' => 'admin', 'subject' => 'user:999']))))->toBe([]);
});

it('pages the grants by the cursor of the manager and starts again when the filter changes', function (): void {
    GrantWorld::editor();
    foreach (range(3, 27) as $id) {
        User::query()->insert(['id' => $id, 'name' => 'User '.$id]);
        AzGuard::panel('admin')->for(User::query()->findOrFail($id))->grantRole('member');
    }
    $component = Livewire::test(ListRoleGrants::class)->set('tableRecordsPerPage', 10);
    $first = array_keys(grantRows($component));
    $component->call('nextPage');
    $second = array_keys(grantRows($component));
    $component->call('nextPage');
    $third = array_keys(grantRows($component));

    expect($first)->toHaveCount(10)
        ->and($second)->toHaveCount(10)
        ->and($third)->toHaveCount(6)
        ->and(array_intersect($first, $second, $third))->toBe([])
        ->and($component->get('grantCursors'))->toHaveKeys([2, 3]);

    $component->filterTable('grants', ['panel' => 'admin', 'subject' => 'user:5']);

    expect(array_keys(grantRows($component)))->toHaveCount(1)
        ->and($component->get('grantCursors'))->toBe([]);
});

it('finds the subject model of a lookup only by its exact key, never by a coerced or invalid id', function (): void {
    GrantWorld::editor();
    $editor = GrantEditor::of(TargetSelector::current()->access('admin'), 'role');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($editor->lookup(SubjectRef::of('user', 'a/b'))->user)->toBeNull()
        ->and($editor->lookup(SubjectRef::of('user', '02'))->user)->toBeNull()
        ->and($editor->lookup(SubjectRef::of('user', '2abc'))->user)->toBeNull()
        ->and($queries)->toBe(0)
        ->and($editor->lookup(SubjectRef::of('user', '2'))->user?->getKey())->toBe(2);
});
