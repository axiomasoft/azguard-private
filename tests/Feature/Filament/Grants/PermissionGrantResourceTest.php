<?php

declare(strict_types=1);

use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Editors\GrantEditor;
use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\PermissionGrantResource\Pages\ListPermissionGrants;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\GrantWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The permission grant editor offers only the permissions decided by grants, refuses a policy-only permission in a
 * forged payload through the writer, and «Why?» shows what `explain()` of the target panel answers.
 */

beforeEach(function (): void {
    GrantWorld::prepare();
    $this->bootFilament();
    GrantWorld::seed();
});

it('offers the permissions decided by grants and never one a policy decides', function (): void {
    GrantWorld::editor();
    $editor = GrantEditor::of(TargetSelector::current()->access('seller'), 'permission');

    expect($editor->grantable())->toHaveKey('entry.enter')
        ->not->toHaveKey('archived-orders.view')
        ->not->toHaveKey('archived-orders.view_any')
        ->and($editor->permissions())->toHaveKeys(['entry.enter', 'archived-orders.view']);
});

it('grants a permission to a subject as the user who edits and lists it', function (): void {
    GrantWorld::editor();

    $component = Livewire::test(ListPermissionGrants::class)
        ->filterTable('grants', ['panel' => 'seller'])
        ->callAction(TestAction::make('create')->table(), ['panel' => 'seller', 'subject' => 'user:2', 'key' => 'entry.enter', 'context_type' => '-'])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');
    $rows = array_values(array_filter($component->instance()->getTableRecords()->all(), fn (array $row): bool => $row['id'] !== "\0next"));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['subject' => 'user:2', 'key' => 'entry.enter', 'granted_by' => 'Member'])
        ->and(AzGuard::panel('seller')->for(User::query()->findOrFail(2))->hasPermission('entry.enter'))->toBeTrue();
});

it('refuses a policy-only permission in a forged payload: the form does not offer it and the writer refuses it', function (): void {
    $user = GrantWorld::editor();
    $rows = [DB::table('azg_permission_grants')->count(), DB::table('azg_panel_state')->where('panel', 'seller')->value('version')];

    Livewire::test(ListPermissionGrants::class)
        ->callAction(TestAction::make('create')->table(), ['panel' => 'seller', 'subject' => 'user:2', 'key' => 'archived-orders.view', 'context_type' => '-'])
        ->assertHasActionErrors(['key']);

    $editor = GrantEditor::of(TargetSelector::current()->access('seller'), 'permission');

    expect(fn () => $editor->grant(['subject' => 'user:2', 'key' => 'archived-orders.view'], $user))->toThrow(PermissionNotGrantableException::class)
        ->and([DB::table('azg_permission_grants')->count(), DB::table('azg_panel_state')->where('panel', 'seller')->value('version')])->toBe($rows);
});

it('V63 answers «Why?» with what explain() of the target panel says', function (): void {
    GrantWorld::editor();
    $receiver = User::query()->findOrFail(2);
    AzGuard::panel('seller')->for($receiver)->grantPermission('entry.enter');
    $request = static fn (string $permission): AccessRequest => AccessRequest::for(SubjectRef::of('user', 2), PermissionKey::of('seller', $permission))
        ->inTenant(TenantRef::global())->traced();
    $expected = AzGuard::panel('seller')->inTenant(TenantRef::global())->explain($request('entry.enter'))->toArray()['decision'];

    $component = Livewire::test(ListPermissionGrants::class)
        ->filterTable('grants', ['panel' => 'seller'])
        ->callAction(TestAction::make('why')->table(), ['subject' => 'user:2', 'permission' => 'entry.enter', 'context_type' => '-'])
        ->assertHasNoActionErrors()
        ->assertActionMounted('explanation');
    $page = $component->instance();
    $entries = collect($page->getSchema((string) $page->getMountedActionSchemaName())?->getFlatComponents() ?? [])
        ->filter(fn ($entry): bool => $entry instanceof TextEntry)
        ->mapWithKeys(fn (TextEntry $entry): array => [$entry->getName() => $entry->getState()]);

    expect($component->get('explanation.decision'))->toBe($expected)
        ->and($entries->all())->toMatchArray([
            'question' => 'Outsider · entry.enter · whole tenant', 'decision' => 'allow', 'reason' => $expected['reason'],
        ])
        ->and($entries['steps'])->not->toBeEmpty()
        ->and(implode("\n", $entries['steps']))->toContain('contribution');

    $denied = AzGuard::panel('seller')->inTenant(TenantRef::global())->explain($request('archived-orders.view'))->toArray()['decision'];

    $component = Livewire::test(ListPermissionGrants::class)
        ->filterTable('grants', ['panel' => 'seller'])
        ->callAction(TestAction::make('why')->table(), ['subject' => 'user:2', 'permission' => 'archived-orders.view', 'context_type' => '-']);

    expect($component->get('explanation.decision'))->toBe($denied)
        ->and($denied['effect'])->not->toBe('allow');
});

it('V63 asks «Why?» from a row with its subject, and shows a role given by no stored grant only there', function (): void {
    GrantWorld::editor();
    $id = GrantWorld::memberGrant(1);

    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('why_grant')->table($id))
        ->assertSchemaStateSet(['subject' => 'user:1', 'permission' => null, 'context_type' => '-'], 'mountedActionSchema0')
        ->fillForm(['permission' => 'entry.enter'])
        ->callMountedAction()
        ->assertActionMounted('explanation');

    expect($component->get('explanation.decision.effect'))->toBe('allow');
});

it('refuses «Why?» for a subject or a permission that the target panel does not know', function (): void {
    GrantWorld::editor();
    $editor = GrantEditor::of(TargetSelector::current()->access('seller'), 'permission');

    expect(fn () => $editor->explain('user:999', 'entry.enter', null, null))->toThrow(ValidationException::class)
        ->and(fn () => $editor->explain('user:2', 'orders.view', null, null))->toThrow(ValidationException::class)
        ->and(fn () => $editor->explain('user:2', 'entry.enter', 'team', '7'))->toThrow(ValidationException::class);
});
