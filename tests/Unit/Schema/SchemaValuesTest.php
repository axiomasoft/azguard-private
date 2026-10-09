<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Schema\AssignmentScopeBindingSchema;
use AzGuard\Schema\AssignmentScopeTypeSchema;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldSchema;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\RoleSchema;
use AzGuard\Schema\SubjectTypeSchema;
use AzGuard\Schema\TenantTypeSchema;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Schema\SchemaAssertions;
use AzGuard\Tests\Fixtures\Storage\Department;
use AzGuard\Tests\Fixtures\Storage\Weekday;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

function schemaPermission(string $local, ?string $group, PermissionAuthority $authority = PermissionAuthority::Grants): PermissionSchema
{
    return new PermissionSchema(PermissionKey::of('crm', $local), $local, $group, null, $authority, false, $local, 'folder', null,
        $authority === PermissionAuthority::Grants ? ['database'] : [], $authority === PermissionAuthority::Grants ? ['crm.project'] : []);
}

function schemaRole(): RoleSchema
{
    return new RoleSchema(RoleKey::of('crm', 'seller'), 'Продавец', SellerRole::class, true, false, true, ['crm.project'],
        [new AssignmentScopeBindingSchema('crm.project', ['App\Filters\SellerProjects', Closure::class], 'Проект', null, true)],
        false, [PermissionPattern::of('crm', 'clients.view'), PermissionPattern::of('crm', 'clients.*')]);
}

it('V72 describes a field with scalars and class names only, whatever rule objects it carries', function (): void {
    $secret = 'token-123';
    $field = Field::string('reason')->label('Причина')->required()->multiple()->inMeta()
        ->rules(['max:200', 5, Rule::in(['a', 'b']), Rule::enum(Weekday::class), static fn (): bool => $secret !== '', new stdClass])
        ->withContribution('plugin:acme/reasons');
    $schema = FieldSchema::of($field);
    // Laravel 12+ renders the enum rule as `in:...`; Laravel 11's Enum rule is not Stringable, so only its class is kept.
    $enumRule = Rule::enum(Weekday::class) instanceof Stringable ? 'in:"1","5"' : Enum::class;

    expect($schema->toArray())->toBe([
        'name' => 'reason', 'label' => 'Причина', 'type' => 'string',
        'rules' => ['required', 'array', 'max:200', '5', 'in:"a","b"', $enumRule, Closure::class, stdClass::class],
        'options' => null, 'in_meta' => true, 'contributed_by' => 'plugin:acme/reasons',
    ])->and(SchemaAssertions::nonScalars($schema->toArray()))->toBe([])
        ->and(json_encode($schema))->not->toContain($secret);
});

it('V72 lists enum options and the exists rule of a model field; an unmarked field comes from the panel', function (): void {
    $enum = FieldSchema::of(Field::enum('day', Weekday::class));
    $model = FieldSchema::of(Field::model('department_id', Department::class)->required());

    expect($enum->options)->toBe([1 => 'Monday', 5 => 'Friday'])->and($enum->rules)->toBe(['nullable'])
        ->and($enum->contributedBy)->toBe('panel')
        ->and($model->type)->toBe('model')->and($model->options)->toBeNull()
        ->and($model->rules)->toBe(['required', 'exists:'.Department::class.',id']);
});

it('V72 keeps a code role read-only and exports its bindings without filter objects', function (): void {
    $role = schemaRole();

    expect($role->editable)->toBeFalse()
        ->and($role->toArray())->toBe([
            'key' => 'seller', 'label' => 'Продавец', 'class' => SellerRole::class, 'editable' => false, 'grantable' => true,
            'automatic' => false, 'scope_required' => true, 'context_types' => ['crm.project'],
            'context_bindings' => [['context_type' => 'crm.project', 'filters' => ['App\Filters\SellerProjects', Closure::class],
                'label' => 'Проект', 'directory' => null, 'exact_support' => true]],
            'super_admin' => false, 'permissions' => ['clients.view', 'clients.*'],
        ])
        ->and((new ReflectionProperty(RoleSchema::class, 'editable'))->isReadOnly())->toBeTrue();
});

it('V72 marks a policy-only permission as not grantable', function (): void {
    expect(schemaPermission('clients.view', 'Клиенты')->grantable())->toBeTrue()
        ->and(schemaPermission('clients.own', null, PermissionAuthority::Policy)->toArray())->toMatchArray([
            'authority' => 'policy', 'grantable' => false, 'sources' => [], 'context_types' => [],
        ]);
});

it('V72 groups permissions by resource group and exports the whole panel as scalars', function (): void {
    $field = FieldSchema::of(Field::string('note'));
    $schema = new PanelSchema('crm', 'CRM', true, TenantRef::of('crm.organization', 1),
        [schemaPermission('clients.view', 'Клиенты'), schemaPermission('audit.read', null), schemaPermission('clients.update', 'Клиенты')],
        [schemaRole()], [FieldTarget::RoleGrant->value => [$field]],
        [new TenantTypeSchema('crm.organization', 'Organization', 'App\Models\Organization', 'App\Directories\Tenants')],
        [new AssignmentScopeTypeSchema('crm.project', 'Проект', null, 'App\Directories\Projects')],
        [new SubjectTypeSchema('App\Models\User', 'crm.user', 'User', 'web', 'App\Directories\Users')]);
    $array = $schema->toArray();

    expect(array_keys($schema->groups()))->toBe(['', 'Клиенты'])
        ->and(array_map(static fn (PermissionSchema $permission): string => $permission->key->local(), $schema->groups()['Клиенты']))
        ->toBe(['clients.view', 'clients.update'])
        ->and($schema->fields(FieldTarget::RoleGrant))->toBe([$field])->and($schema->fields(FieldTarget::PermissionGrant))->toBe([])
        ->and($array['tenant'])->toBe(['key' => 'crm.organization:1', 'type' => 'crm.organization', 'id' => '1'])
        ->and($array['groups'])->toBe(['' => ['crm:audit.read'], 'Клиенты' => ['crm:clients.view', 'crm:clients.update']])
        ->and($array['fields']['permission_grant'])->toBe([])
        ->and(SchemaAssertions::nonScalars($array))->toBe([])
        ->and(json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true))->toBe(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true));
});

it('names every non-scalar value of an exported tree', function (): void {
    expect(SchemaAssertions::nonScalars(['a' => [1, 'x', null, true, 1.5], 'b' => [new stdClass], 'c' => static fn () => null]))
        ->toBe(['$.b.0: stdClass', '$.c: Closure']);
});

it('describes a source by its id when it gives no label and carries no fields by default', function (): void {
    $description = new SourceDescription('folder', FolderSource::class, [], false);
    $labelled = new SourceDescription('folder', FolderSource::class, [], false, 'Folder', ['role_grant' => [Field::string('note')]]);

    expect($description->label)->toBe('folder')->and($description->fields)->toBe([])
        ->and($labelled->label)->toBe('Folder')->and($labelled->fields['role_grant'][0]->name())->toBe('note');
});
