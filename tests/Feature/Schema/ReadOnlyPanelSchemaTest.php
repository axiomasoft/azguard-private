<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Schema\SchemaBuilder;
use AzGuard\Tests\Fixtures\Schema\SchemaAssertions;
use AzGuard\Tests\Fixtures\Sources\LdapSource;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;

beforeEach(fn () => RelationWorld::seed());
afterEach(fn () => RelationWorld::reset());

it('V72 describes a panel without a writer as read-only, and a role source only for what its roles cover', function (): void {
    [$panel] = RelationWorld::compile([new LdapSource(['bind_password' => 'ldap-secret-31'])]);
    $schema = (new SchemaBuilder(app(PanelRegistry::class), app()))->for($panel, TenantRef::global());
    $sources = [];

    foreach ($schema->permissions() as $permission) {
        $sources[$permission->name] = $permission->sources;
    }

    expect($schema->writable)->toBeFalse()
        ->and($schema->fields(FieldTarget::RoleGrant))->toBe([])->and($schema->fields(FieldTarget::PermissionGrant))->toBe([])
        ->and($schema->tenants())->toBe([])
        // ldap gives role grants only: the permissions of its roles, never a permission no role covers.
        ->and($sources)->toBe(['ldap.sync' => [], 'projects.edit' => ['ldap'], 'projects.view' => ['ldap']])
        ->and(array_map(static fn (PermissionSchema $permission): string => $permission->owner, $schema->permissions()))
        ->toBe(['ldap', 'folder', 'folder'])
        ->and(SchemaAssertions::nonScalars($schema->toArray()))->toBe([])
        ->and(SchemaAssertions::json($schema))->not->toContain('ldap-secret-31');
});
