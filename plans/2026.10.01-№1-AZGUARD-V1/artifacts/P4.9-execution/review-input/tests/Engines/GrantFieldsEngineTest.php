<?php

declare(strict_types=1);

use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\GrantFields;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\AdminRoleGrant;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;
use Illuminate\Database\Schema\Blueprint;

it('roundtrips custom grant columns and JSON meta on the server', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');
    $ddl = $storage->connection()->getSchemaBuilder();
    $ddl->dropIfExists('departments');
    $ddl->create('departments', function (Blueprint $table): void {
        $table->id();
    });
    $storage->connection()->table('departments')->insert(['id' => 7]);
    $ddl->table($storage->prefix().'role_grants', function (Blueprint $table): void {
        $table->integer('department_id')->nullable();
    });

    try {
        $fields = GrantFields::for($storage, FieldTarget::RoleGrant, AdminRoleGrant::class, decisionFields: ['weekdays']);
        $row = $fields->toRow(['department_id' => 7, 'weekdays' => [1, 5]]);
        $storage->mutate('admin', function (StorageMutation $mutation) use ($row): void {
            $mutation->table('role_grants')->insert(SchemaAssertions::grant() + $row['columns'] + ['meta' => json_encode($row['meta'])]);
            $mutation->touch('admin');
        });
        $grant = $storage->model('role_grant', AdminRoleGrant::class)->newQuery()->firstOrFail();
        expect((int) $grant->department_id)->toBe(7)->and($fields->decisionValues($grant))->toBe(['weekdays' => [1, 5]])
            ->and($storage->state('admin')->version)->toBe(1);
    } finally {
        $schema->drop('default');
        $ddl->dropIfExists('departments');
    }
})->group('engines');
