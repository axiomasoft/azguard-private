<?php

declare(strict_types=1);

use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\RoleSchema;
use AzGuard\Schema\SchemaBuilder;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\InjectedSellerFilter;
use AzGuard\Tests\Fixtures\Schema\RegionalSellerRole;
use AzGuard\Tests\Fixtures\Schema\RegionProjects;
use AzGuard\Tests\Fixtures\Schema\SchemaAssertions;
use AzGuard\Tests\Fixtures\Schema\SecretFieldPlugin;

function crmPanelSchema(Panel $panel, int $tenant = 1): PanelSchema
{
    return (new SchemaBuilder(app(PanelRegistry::class), app()))->for($panel, TenantRef::of('crm.organization', $tenant));
}

it('R49 matches the stored JSON snapshot of the CRM panel schema, in a deterministic order', function (): void {
    $path = dirname(__DIR__, 2).'/Fixtures/Schema/crm-panel-schema.json';
    $json = SchemaAssertions::json(crmPanelSchema(World::compile()));

    // A deliberate snapshot change is written locally with UPDATE_SNAPSHOTS=1 and reviewed as a diff.
    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents($path, $json);
    }

    expect($json)->toBe(file_get_contents($path))
        ->and(SchemaAssertions::json(crmPanelSchema(World::compile())))->toBe($json)
        ->and(SchemaAssertions::nonScalars(json_decode($json, true, flags: JSON_THROW_ON_ERROR)))->toBe([]);
});

it('R49 keeps the typed secret of a plugin, live models and the container out of the schema', function (): void {
    $schema = crmPanelSchema(World::compile(static fn (PanelBuilder $panel) => $panel->plugins([SecretFieldPlugin::make(SecretFieldPlugin::SECRET)])));
    $json = SchemaAssertions::json($schema);

    expect(SchemaAssertions::nonScalars($schema->toArray()))->toBe([])
        ->and($json)->toContain('plugin:acme/reasons')
        ->not->toContain(SecretFieldPlugin::SECRET)->not->toContain('Анна')->not->toContain('Казань')->not->toContain('Illuminate\\Foundation');
});

it('R24 shows a context binding with several typed filters as class metadata, in declaration order', function (): void {
    $schema = crmPanelSchema(World::compile(static fn (PanelBuilder $panel) => $panel->roles([RegionalSellerRole::class])));
    $role = current(array_filter($schema->roles(), static fn (RoleSchema $role): bool => $role->key->key() === 'regional-seller'));

    expect($role->label)->toBe('Региональный продавец')->and($role->editable)->toBeFalse()
        ->and($role->contextBindings)->toHaveCount(1)
        ->and($role->contextBindings[0]->toArray())->toBe([
            'context_type' => 'crm.project',
            'filters' => [SellerProjects::class, InjectedSellerFilter::class, RegionProjects::class],
            'label' => 'Проект региона', 'directory' => null, 'exact_support' => true,
        ])
        // The constructor argument of the typed filter is configuration in code, not schema data.
        ->and(SchemaAssertions::json($schema))->not->toContain('"R1"');
});

it('R38 gives the editor badge data that the writer enforces: PolicyOnly and code-only roles are not assignable', function (): void {
    $panel = W::panel();
    $schema = crmPanelSchema($panel);
    $notGrantable = array_values(array_filter($schema->permissions(), static fn (PermissionSchema $permission): bool => ! $permission->grantable()));
    $codeOnly = array_values(array_filter($schema->roles(), static fn (RoleSchema $role): bool => ! $role->grantable));

    expect(array_map(static fn (PermissionSchema $permission): string => $permission->key->local(), $notGrantable))->toBe(['clients.view_own_profile'])
        ->and($notGrantable[0]->decidedBy)->toBe('policy:'.ClientPolicy::class.'@own')
        ->and($notGrantable[0]->sources)->toBe([])
        ->and(array_map(static fn (RoleSchema $role): string => $role->key->key(), $codeOnly))->toBe(['root'])
        ->and(array_unique(array_map(static fn (RoleSchema $role): bool => $role->editable, $schema->roles())))->toBe([false]);
    $before = [W::rows('role'), W::rows('permission'), W::version()];

    // A forged payload for a badge-only permission or a code-only role is refused by the writer without a row.
    expect(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), PermissionPattern::of('crm', 'clients.view_own_profile'), W::project(2)))
        ->toThrow(PermissionNotGrantableException::class)
        ->and(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::role('root'), W::project(2)))
        ->toThrow(RoleNotGrantableException::class)
        ->and([W::rows('role'), W::rows('permission'), W::version()])->toBe($before);
});
