<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Filament\Forms\SchemaFields;
use AzGuard\Filament\Resources\PermissionGrantResource\Pages\ListPermissionGrants;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\Fields\DepartmentTreeExtension;
use AzGuard\Tests\Fixtures\Filament\Fields\ReasonExtension;
use AzGuard\Tests\Fixtures\Filament\Fields\StrayFieldExtension;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GrantWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Storage\Department;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The grant fields of the schema in the grant editors: the fields of the grant model of a panel and of its AzGuard
 * plugins, the components of the form extensions of the Filament plugin, and the writer as the only check of the values.
 */

beforeEach(function (): void {
    GrantWorld::prepare();
    FilamentFixture::$grantFields = true;
    DepartmentTreeExtension::$built = 0;
});

/** Seeds the world and the departments after the application booted. */
function seedFields(): void
{
    GrantWorld::seed();
    Schema::create('departments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Department::query()->insert([['id' => 1, 'name' => 'Sales'], ['id' => 2, 'name' => 'Support']]);
}

/**
 * The fields of the open action form under the grant fields, by name.
 *
 * @return array<string, Field>
 */
function grantFields(Testable $component): array
{
    $page = $component->instance();
    $fields = [];

    foreach ($page->getSchema((string) $page->getMountedActionSchemaName())?->getFlatFields() ?? [] as $field) {
        $path = $field->getStatePath();
        $prefix = 'mountedActions.0.data.'.SchemaFields::PATH.'.';

        if (str_starts_with($path, $prefix)) {
            $fields[substr($path, strlen($prefix))] = $field;
        }
    }
    ksort($fields);

    return $fields;
}

function storageVersion(string $panel): int
{
    return app(StorageRegistry::class)->get('default')->state($panel)?->version ?? 0;
}

it('V62 shows the field of the grant model and the field of the AzGuard plugin, and the fields of the panel chosen', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();

    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'admin']);
    $fields = grantFields($component);

    expect(array_keys($fields))->toBe(['department_id', 'reason'])
        ->and($fields['department_id']->getLabel())->toBe('Department')
        ->and($fields['reason'])->toBeInstanceOf(TextInput::class)
        ->and($fields['reason']->getLabel())->toBe('Reason');

    $component->fillForm(['panel' => 'seller']);

    expect(grantFields($component))->toBe([]);

    $component->fillForm(['panel' => 'teams', 'tenant' => '7']);

    expect(array_keys(grantFields($component)))->toBe(['reason']);
});

it('V62 sends the values of the fields to the writer and stores them with the grant', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-',
            'fields' => ['department_id' => '2', 'reason' => 'audit'],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');

    $record = AzGuard::panel('admin')->grants()->find(GrantWorld::memberGrant(2));

    expect($record?->fields)->toMatchArray(['department_id' => 2, 'reason' => 'audit']);
});

it('V62 refuses a forged field in the payload as an error of the form and writes no grant and no version', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    $rows = DB::table('azg_role_grants')->count();
    $version = storageVersion('default');

    Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-', 'fields' => ['reason' => 'audit']])
        ->set('mountedActions.0.data.fields.approved_by', 'root')
        ->callMountedAction()
        ->assertHasActionErrors(['fields.approved_by'])
        ->assertNotNotified('Saved');

    expect(DB::table('azg_role_grants')->count())->toBe($rows)
        ->and(storageVersion('default'))->toBe($version);
});

it('V62 leaves the rules of a field to the writer, which refuses a value that breaks them', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    $rows = DB::table('azg_permission_grants')->count();

    Livewire::test(ListPermissionGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'orders.view', 'context_type' => '-',
            'fields' => ['reason' => str_repeat('x', 21)],
        ])
        ->assertHasActionErrors(['fields.reason']);

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-',
            'fields' => ['department_id' => '99'],
        ])
        ->assertHasActionErrors(['fields.department_id']);

    expect(DB::table('azg_permission_grants')->count())->toBe($rows)
        ->and(DB::table('azg_role_grants')->where('subject_id', 2)->count())->toBe(0);
});

it('edits the fields of a row and refuses a forged field of the edit form', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    AzGuard::panel('admin')->for(User::query()->findOrFail(2))->grantRole('member', fields: ['reason' => 'audit']);
    $id = GrantWorld::memberGrant(2);

    Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('edit')->table($id))
        ->assertSet('mountedActions.0.data.fields.reason', 'audit')
        ->set('mountedActions.0.data.fields.approved_by', 'root')
        ->callMountedAction()
        ->assertHasActionErrors(['fields.approved_by'])
        ->set('mountedActions.0.data.fields', ['department_id' => '1', 'reason' => 'renewal'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Saved');

    expect(AzGuard::panel('admin')->grants()->find($id)?->fields)->toMatchArray(['department_id' => 1, 'reason' => 'renewal']);
});

it('V96 clears the fields when the tenant or the panel of the form changes', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();

    $component = Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'teams', 'tenant' => '7', 'fields' => ['reason' => 'team seven']])
        ->assertSet('mountedActions.0.data.fields.reason', 'team seven')
        ->fillForm(['tenant' => '8'])
        ->assertSet('mountedActions.0.data.fields', [])
        ->fillForm(['fields' => ['reason' => 'team eight']])
        ->fillForm(['panel' => 'admin'])
        ->assertSet('mountedActions.0.data.fields', []);

    expect(array_keys(grantFields($component)))->toBe(['department_id', 'reason']);
});

it('replaces the component of a field with the component of an extension for its target only', function (): void {
    FilamentFixture::$formExtensions = ['admin' => [DepartmentTreeExtension::class]];
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();

    $roleFields = grantFields(Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'admin']));
    $permissionFields = grantFields(Livewire::test(ListPermissionGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'admin']));

    expect(array_keys($roleFields))->toBe(['department_id', 'reason'])
        ->and($roleFields['department_id'])->toBeInstanceOf(Select::class)
        ->and($roleFields['department_id']->getLabel())->toStartWith('Department tree')
        ->and($roleFields['department_id']->getOptions())->toBe([1 => 'Sales', 2 => 'Support'])
        ->and($roleFields['reason'])->toBeInstanceOf(TextInput::class)
        ->and(array_keys($permissionFields))->toBe(['reason'])
        ->and($permissionFields['reason'])->toBeInstanceOf(TextInput::class);

    Livewire::test(ListRoleGrants::class)
        ->callAction(TestAction::make('create')->table(), [
            'panel' => 'admin', 'subject' => 'user:2', 'key' => 'member', 'context_type' => '-', 'fields' => ['department_id' => 1],
        ])
        ->assertHasNoActionErrors();

    expect(AzGuard::panel('admin')->grants()->find(GrantWorld::memberGrant(2))?->fields)->toMatchArray(['department_id' => 1]);
});

it('resolves an extension class from the container for every form, so a form keeps nothing of another', function (): void {
    FilamentFixture::$formExtensions = ['admin' => [DepartmentTreeExtension::class]];
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    $plugin = AzGuardPlugin::get('admin');

    [$first] = $plugin->resolveFormExtensions();
    [$second] = $plugin->resolveFormExtensions();
    $schema = AzGuard::panel('admin')->schema();
    $labels = array_map(
        static fn (): string => (string) $plugin->resolveFormExtensions()[0]->components($schema, FieldTarget::RoleGrant)['department_id']->getLabel(),
        [1, 2],
    );

    expect($first)->not->toBe($second)
        ->and($labels)->toBe(['Department tree #1', 'Department tree #1']);
});

it('refuses an extension that gives a component for a field the schema does not declare', function (): void {
    FilamentFixture::$formExtensions = ['admin' => [StrayFieldExtension::class]];
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    $schema = AzGuard::panel('admin')->schema();

    expect(fn () => SchemaFields::for($schema, FieldTarget::RoleGrant, [new StrayFieldExtension]))
        ->toThrow(InvalidConfigurationException::class, 'names no field of the schema')
        ->and(fn () => Livewire::test(ListRoleGrants::class)
            ->mountAction(TestAction::make('create')->table())
            ->fillForm(['panel' => 'admin']))
        ->toThrow(InvalidConfigurationException::class);
});

it('refuses an extension component that is not a form field of the name it is given for', function (): void {
    $this->bootFilament();
    seedFields();
    GrantWorld::editor();
    $schema = AzGuard::panel('admin')->schema();
    $renamed = new class implements FilamentFormExtension
    {
        public function appliesTo(PanelSchema $schema, FieldTarget $target): bool
        {
            return true;
        }

        public function components(PanelSchema $schema, FieldTarget $target): array
        {
            return ['reason' => TextInput::make('approved_by')];
        }
    };

    expect(fn () => SchemaFields::for($schema, FieldTarget::RoleGrant, [$renamed]))
        ->toThrow(InvalidConfigurationException::class, 'must be a form field named "reason"');
});

it('refuses a registered class that is not a form extension when a form is built', function (): void {
    FilamentFixture::$formExtensions = ['admin' => [User::class]];
    $this->bootFilament();
    seedFields();

    expect(fn () => AzGuardPlugin::get('admin')->resolveFormExtensions())
        ->toThrow(InvalidConfigurationException::class, 'does not implement');
});

it('keeps the form extensions of two Filament panels apart', function (): void {
    FilamentFixture::$formExtensions = ['admin' => [DepartmentTreeExtension::class], 'tenanted' => [ReasonExtension::class]];
    $this->bootFilament();
    seedFields();

    expect(AzGuardPlugin::get('admin')->getFormExtensions())->toBe([DepartmentTreeExtension::class])
        ->and(AzGuardPlugin::get('tenanted')->getFormExtensions())->toBe([ReasonExtension::class]);

    GrantWorld::editor();
    $admin = grantFields(Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'teams', 'tenant' => '7']));

    EditorWorld::teamEditor(GrantWorld::permissions());
    $tenanted = grantFields(Livewire::test(ListRoleGrants::class)
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['panel' => 'teams', 'tenant' => '7']));

    expect($admin['reason'])->toBeInstanceOf(TextInput::class)
        ->and($tenanted['reason'])->toBeInstanceOf(Textarea::class)
        ->and($tenanted['reason']->getLabel())->toBe('Reason in teams')
        ->and(AzGuard::panel('teams')->inTenant(TenantRef::of('team', 7))->schema()->fields(FieldTarget::RoleGrant))->toHaveCount(1);
});
