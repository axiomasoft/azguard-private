<?php

declare(strict_types=1);

use AzGuard\Directories\ModelSubjectDirectory;
use AzGuard\Directories\ModelTenantDirectory;
use AzGuard\Directories\QueryScopeDirectory;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\RoleSchema;
use AzGuard\Schema\SchemaBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmPermissionGrant;
use AzGuard\Tests\Fixtures\Crm\CrmRoleGrant;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\City;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Crm\ProjectDirectory;
use AzGuard\Tests\Fixtures\Schema\AutomaticViewerRole;
use AzGuard\Tests\Fixtures\Schema\HrRoleSource;
use AzGuard\Tests\Fixtures\Schema\ReportPermission;
use AzGuard\Tests\Fixtures\Schema\SchemaAssertions;
use AzGuard\Tests\Fixtures\Schema\SchemaSubjectDirectory;
use AzGuard\Tests\Fixtures\Schema\SecretFieldPlugin;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => World::seed());
afterEach(function (): void {
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function crmSchema(Panel $panel, ?int $tenant = 1): PanelSchema
{
    $schema = (new SchemaBuilder(app(PanelRegistry::class), app()))
        ->for($panel, $tenant === null ? TenantRef::global() : TenantRef::of('crm.organization', $tenant));

    // Every schema of this file is a snapshot of scalars.
    expect(SchemaAssertions::nonScalars($schema->toArray()))->toBe([]);

    return $schema;
}

function schemaPermissionOf(PanelSchema $schema, string $local): PermissionSchema
{
    foreach ($schema->permissions() as $permission) {
        if ($permission->key->local() === $local) {
            return $permission;
        }
    }

    throw new RuntimeException('No permission '.$local.' in the schema.');
}

function schemaRoleOf(PanelSchema $schema, string $key): RoleSchema
{
    foreach ($schema->roles() as $role) {
        if ($role->key->key() === $key) {
            return $role;
        }
    }

    throw new RuntimeException('No role '.$key.' in the schema.');
}

/** The extended CRM panel: a GrantedToAll enum, an automatic role, a gate binding and a role-only source. */
function extendedCrm(): Panel
{
    Gate::define('beta-access', static fn (): bool => true);

    return World::compile(static function (PanelBuilder $panel): void {
        $panel->permissions([ReportPermission::class])->roles([AutomaticViewerRole::class])
            ->policies([PolicyBinding::gate(ReportPermission::Export, 'beta-access')]);
    }, [World::database(), new HrRoleSource(HrRoleSource::PASSWORD)]);
}

it('V72 tells for every permission who decides it, which sources give it and in which context types', function (): void {
    $schema = crmSchema(extendedCrm());

    expect(array_map(static fn (PermissionSchema $permission): string => $permission->key->full(), $schema->permissions()))->toBe([
        'crm:clients.update', 'crm:clients.view', 'crm:clients.view_any', 'crm:clients.view_own_profile', 'crm:reports.export', 'crm:reports.view',
    ])
        ->and(schemaPermissionOf($schema, 'clients.view')->toArray())->toBe([
            'key' => 'crm:clients.view', 'local' => 'clients.view', 'name' => 'crm.clients.view', 'label' => 'clients.view',
            'group' => 'Клиенты', 'description' => null, 'authority' => 'grants', 'grantable' => true, 'dynamic' => false,
            'owner' => 'folder', 'decided_by' => 'policy:'.ClientPolicy::class.'@view',
            // Database grants directly; hr grants roles and the super admin role covers every Grants permission.
            'sources' => ['database', 'hr'], 'context_types' => ['crm.project'],
        ])
        ->and(schemaPermissionOf($schema, 'reports.export')->toArray())->toMatchArray([
            'label' => 'Выгрузка отчётов', 'group' => 'Отчёты', 'description' => 'CSV за период', 'decided_by' => 'gate:beta-access',
            'sources' => ['database', 'hr'],
        ])
        // The folder gives a GrantedToAll permission and the permissions of an automatic role, nothing else.
        ->and(schemaPermissionOf($schema, 'reports.view')->sources)->toBe(['folder', 'database', 'hr'])
        ->and(schemaPermissionOf($schema, 'clients.view_any')->sources)->toBe(['folder', 'database', 'hr'])
        ->and(schemaPermissionOf($schema, 'clients.update')->sources)->toBe(['database', 'hr']);

    $policyOnly = schemaPermissionOf($schema, 'clients.view_own_profile');
    expect($policyOnly->authority)->toBe(PermissionAuthority::Policy)->and($policyOnly->grantable())->toBeFalse()
        ->and($policyOnly->sources)->toBe([])->and($policyOnly->contextTypes)->toBe([])
        ->and($policyOnly->decidedBy)->toBe('policy:'.ClientPolicy::class.'@own');
});

it('V72 shows PHP roles read-only with their class, grant modes and context bindings', function (): void {
    $schema = crmSchema(extendedCrm());
    $seller = schemaRoleOf($schema, 'seller');
    $viewer = schemaRoleOf($schema, 'viewer');

    expect(array_map(static fn (RoleSchema $role): string => $role->key->key(), $schema->roles()))
        ->toBe(['analyst', 'caller', 'seller', 'tenant-admin', 'viewer'])
        ->and(array_unique(array_map(static fn (RoleSchema $role): bool => $role->editable, $schema->roles())))->toBe([false])
        ->and($seller->toArray())->toBe([
            'key' => 'seller', 'label' => 'seller', 'class' => SellerRole::class, 'editable' => false, 'grantable' => true,
            'automatic' => false, 'scope_required' => true, 'context_types' => ['crm.project'],
            'context_bindings' => [['context_type' => 'crm.project', 'filters' => ['AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects'],
                'label' => 'crm.project', 'directory' => null, 'exact_support' => true]],
            'super_admin' => false, 'permissions' => ['clients.view', 'clients.update', 'clients.view_any'],
        ])
        ->and([$viewer->label, $viewer->grantable, $viewer->automatic, $viewer->scopeRequired, $viewer->contextTypes, $viewer->contextBindings])
        ->toBe(['Наблюдатель', false, true, false, [], []])
        ->and(schemaRoleOf($schema, 'tenant-admin')->superAdmin)->toBeTrue();
});

it('V72 gives the grant fields of the writer models and of a plugin with their origin, sorted by name', function (): void {
    $panel = World::compile(static fn (PanelBuilder $panel) => $panel->plugins([SecretFieldPlugin::make(SecretFieldPlugin::SECRET)]));
    $schema = crmSchema($panel);
    $fields = $schema->toArray()['fields'];

    expect(array_column($fields['role_grant'], 'contributed_by', 'name'))->toBe([
        'eligible' => 'model:AzGuard\Tests\Fixtures\Crm\CrmRoleGrant', 'reason' => 'plugin:acme/reasons',
        'region' => 'model:AzGuard\Tests\Fixtures\Crm\CrmRoleGrant', 'weekday' => 'plugin:acme/reasons',
    ])
        ->and($fields['role_grant'][1])->toBe([
            'name' => 'reason', 'label' => 'Причина выдачи', 'type' => 'string',
            'rules' => ['required', 'max:200', 'in:"audit","onboarding"', Closure::class], 'options' => null, 'in_meta' => false,
            'contributed_by' => 'plugin:acme/reasons',
        ])
        ->and($fields['role_grant'][3])->toMatchArray(['type' => 'enum', 'rules' => ['nullable', 'array'], 'options' => [1 => 'Monday', 5 => 'Friday'], 'in_meta' => true])
        ->and(array_column($fields['permission_grant'], 'name'))->toBe(['eligible', 'region'])
        ->and($schema->fields(FieldTarget::PermissionGrant)[0]->contributedBy)->toBe('model:AzGuard\Tests\Fixtures\Crm\CrmPermissionGrant');
});

it('V104 R49 never serializes a secret parameter of a plugin or of a source', function (): void {
    $panel = World::compile(
        static fn (PanelBuilder $panel) => $panel->plugins([SecretFieldPlugin::make(SecretFieldPlugin::SECRET)]),
        [World::database(), new HrRoleSource(HrRoleSource::PASSWORD)],
    );
    $json = SchemaAssertions::json(crmSchema($panel));

    expect($json)->toContain('acme/reasons')->toContain('"hr"')
        ->not->toContain(SecretFieldPlugin::SECRET)->not->toContain(HrRoleSource::PASSWORD)->not->toContain('apiToken')->not->toContain('password')
        // Panel source descriptions stay without parameters as well.
        ->and(serialize(array_map(static fn ($description): array => (array) $description, $panel->sources())))
        ->not->toContain(HrRoleSource::PASSWORD);
});

it('V104 overlays the dynamic permissions of the selected tenant only', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'campaigns.view', tenant: 1, label: 'Просмотр кампаний', group: 'Кампании');
    W::createAction($panel, 'calls.make', tenant: 2, label: 'Звонки');
    $queries = [];
    $listening = true;
    DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
        if ($listening) {
            $queries[] = $query->sql;
        }
    });
    $a = crmSchema($panel, 1);
    $listening = false;
    // One read of the definitions of the tenant; the state reads around it fence that read.
    $reads = array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/\bfrom\s+["`]?[a-z_]*permissions["`]?(\s|$)/i', $sql) === 1));
    $b = crmSchema($panel, 2);
    $dynamic = static fn (PanelSchema $schema): array => array_values(array_map(
        static fn (PermissionSchema $permission): string => $permission->key->local(),
        array_filter($schema->permissions(), static fn (PermissionSchema $permission): bool => $permission->dynamic),
    ));

    expect($dynamic($a))->toBe(['campaigns.view'])->and($dynamic($b))->toBe(['calls.make'])
        ->and($reads)->toHaveCount(1)
        ->and(schemaPermissionOf($a, 'campaigns.view')->toArray())->toMatchArray([
            'label' => 'Просмотр кампаний', 'group' => 'Кампании', 'authority' => 'grants', 'dynamic' => true, 'owner' => 'database',
            'decided_by' => null, 'sources' => ['database'], 'context_types' => ['crm.project'],
        ])
        ->and($a->groups()['Кампании'][0]->key->full())->toBe('crm:campaigns.view')
        ->and(array_key_exists('Кампании', $b->groups()))->toBeFalse()
        // A tenant panel asked without a tenant never gathers the dynamic permissions of the tenants.
        ->and($dynamic(crmSchema($panel, null)))->toBe([])
        ->and(crmSchema($panel, null)->tenant->isGlobal())->toBeTrue();
});

it('rejects a tenant of a type the panel does not use', function (): void {
    expect(fn () => (new SchemaBuilder(app(PanelRegistry::class), app()))->for(World::compile(), TenantRef::of('crm.user', 1)))
        ->toThrow(TenantMismatchException::class);
});

it('takes the auth guard and the directory of subjects from the compiled descriptor, the defaults from the resolver', function (): void {
    $default = crmSchema(World::compile());
    Relation::morphMap(['crm.city' => City::class], false);
    $custom = crmSchema(World::compile(static function (PanelBuilder $panel): void {
        $panel->for(model: City::class, guard: 'admin', directory: SchemaSubjectDirectory::class)
            ->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->label('Проект')->directory(ProjectDirectory::class)));
    }));

    expect($default->toArray()['subjects'])->toBe([[
        'model' => User::class, 'type' => 'crm.user', 'label' => 'User', 'guard' => 'web', 'directory' => ModelSubjectDirectory::class,
    ]])
        ->and($default->toArray()['tenants'])->toBe([[
            'type' => 'crm.organization', 'label' => 'Organization', 'model' => 'AzGuard\Tests\Fixtures\Crm\Models\Organization',
            'directory' => ModelTenantDirectory::class,
        ]])
        ->and($default->toArray()['scopes'])->toBe([[
            'type' => 'crm.project', 'label' => 'crm.project', 'model' => 'AzGuard\Tests\Fixtures\Crm\Models\Project', 'directory' => QueryScopeDirectory::class,
        ]])
        ->and($custom->toArray()['subjects'])->toBe([
            ['model' => User::class, 'type' => 'crm.user', 'label' => 'User', 'guard' => 'web', 'directory' => ModelSubjectDirectory::class],
            ['model' => City::class, 'type' => 'crm.city', 'label' => 'City', 'guard' => 'admin', 'directory' => SchemaSubjectDirectory::class],
        ])
        ->and($custom->scopes()[0]->label)->toBe('Проект')->and($custom->scopes()[0]->directory)->toBe(ProjectDirectory::class)
        ->and($default->writable)->toBeTrue()->and($default->label)->toBe('crm')->and($default->panel)->toBe('crm');
});

it('R49 builds the same metadata twice for the same build', function (): void {
    $panel = extendedCrm();

    expect(SchemaAssertions::json(crmSchema($panel)))->toBe(SchemaAssertions::json(crmSchema(World::compile(static function (PanelBuilder $panel): void {
        $panel->permissions([ReportPermission::class])->roles([AutomaticViewerRole::class])
            ->policies([PolicyBinding::gate(ReportPermission::Export, 'beta-access')]);
    }, [World::database(), new HrRoleSource('another-secret')]))));
});

it('describes sources with a label and the grant fields of the writer models, without parameters', function (): void {
    $panel = World::compile(null, [World::database(), new HrRoleSource(HrRoleSource::PASSWORD)]);
    $descriptions = [];

    foreach ($panel->sources() as $description) {
        $descriptions[$description->id] = $description;
    }
    $fieldNames = static fn (array $fields): array => array_map(static fn (Field $field): string => $field->name().'@'.$field->contributedBy(), $fields);
    $rolesOnly = World::database()->rolesOnly();
    World::compile(null, [$rolesOnly]);

    expect(array_map(static fn ($description): string => $description->label, $descriptions))
        ->toBe(['folder' => 'Folder', 'database' => 'Database', 'hr' => 'hr'])
        ->and($fieldNames($descriptions['database']->fields['role_grant']))
        ->toBe(['region@model:'.CrmRoleGrant::class, 'eligible@model:'.CrmRoleGrant::class])
        ->and($fieldNames($descriptions['database']->fields['permission_grant']))
        ->toBe(['region@model:'.CrmPermissionGrant::class, 'eligible@model:'.CrmPermissionGrant::class])
        ->and($descriptions['folder']->fields)->toBe([])->and($descriptions['hr']->fields)->toBe([])
        // A roles-only writer stores no direct grants, so it offers no fields for them.
        ->and($fieldNames($rolesOnly->describe(app(PanelRegistry::class)->get('crm'))->fields['permission_grant']))->toBe([])
        // The default models are described without a database read.
        ->and($fieldNames(DatabaseSource::make()->describe(World::compile())->fields['role_grant']))->toBe([]);
});
