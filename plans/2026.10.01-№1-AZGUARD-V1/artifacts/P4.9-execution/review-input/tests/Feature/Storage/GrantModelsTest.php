<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('hydrates concrete grant models with their storage and typed identity', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $row = ['panel' => 'admin', 'tenant_key' => 'organization:2', 'tenant_type' => 'organization', 'tenant_id' => '2',
        'subject_type' => 'user', 'subject_id' => '007', 'context_key' => 'project:3', 'context_type' => 'project',
        'context_id' => '3', 'origin' => 'manual', 'actor_type' => 'user', 'actor_id' => '8', 'actor_reason' => 'test',
        'expires_at' => '2040-01-02 03:04:05', 'meta' => '{"note":"hello"}'];
    $storage->mutate('admin', function (StorageMutation $write) use ($row): void {
        $write->table('role_grants')->insert([...$row, 'role' => 'editor']);
        $write->table('permission_grants')->insert([...$row, 'permission' => 'orders.*']);
    });
    $role = $storage->model('role_grant')->newQuery()->firstOrFail();
    $grant = $storage->model('permission_grant')->newQuery()->firstOrFail();

    expect($role)->toBeInstanceOf(RoleGrant::class)
        ->and($role->storage())->toBe($storage)
        ->and($role->getTable())->toBe('azg_role_grants')
        ->and($role->getConnectionName())->toBe($storage->connectionName())
        ->and($role->tenantRef()->equals(TenantRef::of('organization', '2')))->toBeTrue()
        ->and($role->subjectRef()->id())->toBe('007')
        ->and($role->assignmentScopeRef()->key())->toBe('project:3')
        ->and($role->roleKey()->full())->toBe('admin:editor')
        ->and($role->origin())->toBe('manual')
        ->and($role->actorRef())->toEqual(ActorRef::of('user', '8', 'test'))
        ->and($role->expiresAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($role->expiresAt()->format('c'))->toBe('2040-01-02T03:04:05+00:00')
        ->and($role->meta)->toBe(['note' => 'hello'])
        ->and($grant)->toBeInstanceOf(PermissionGrant::class)
        ->and($grant->permissionKey())->toEqual(PermissionPattern::of('admin', 'orders.*'))
        ->and($grant->storage())->toBe($storage);
});

it('decodes global null and system actor forms and exact permissions', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $model = $storage->model('permission_grant')->newFromBuilder([
        'panel' => 'admin', 'tenant_key' => 'global', 'context_key' => 'global',
        'subject_type' => 'user', 'subject_id' => 7, 'permission' => 'orders.view', 'origin' => 'import',
        'actor_type' => ActorRef::SYSTEM_TYPE, 'actor_reason' => 'service',
    ]);

    expect($model->tenantRef()->isGlobal())->toBeTrue()
        ->and($model->assignmentScopeRef()->isGlobal())->toBeTrue()
        ->and($model->subjectRef()->id())->toBe('7')
        ->and($model->actorRef())->toEqual(ActorRef::system('service'))
        ->and($model->expiresAt())->toBeNull()
        ->and($model->permissionKey())->toEqual(PermissionKey::of('admin', 'orders.view'));
    $model->setRawAttributes([...$model->getAttributes(), 'actor_type' => null]);
    expect($model->actorRef())->toBeNull();

    $permission = $storage->model('permission')->newFromBuilder(['panel' => 'admin', 'tenant_key' => 'global', 'name' => 'orders.view', 'meta' => '{"label":"Orders"}']);
    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->permissionKey())->toEqual(PermissionKey::of('admin', 'orders.view'))
        ->and($permission->meta)->toBe(['label' => 'Orders'])
        ->and(RoleGrant::azguardFields())->toBe([])
        ->and(PermissionGrant::azguardFields())->toBe([]);
});

it('rejects malformed stored references and host keys instead of changing identity', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $role = $storage->model('role_grant')->newFromBuilder(['tenant_key' => 'global', 'tenant_type' => 'organization', 'tenant_id' => '2']);
    expect(fn () => $role->tenantRef())->toThrow(InvalidIdentityException::class);
    $role->setRawAttributes(['context_key' => 'project:3', 'context_type' => 'project']);
    expect(fn () => $role->assignmentScopeRef())->toThrow(InvalidIdentityException::class);
    $role->setRawAttributes(['subject_type' => 'user', 'subject_id' => 'bad id']);
    expect(fn () => $role->subjectRef())->toThrow(InvalidIdentityException::class);
    $role->setRawAttributes(['actor_id' => '3']);
    expect(fn () => $role->actorRef())->toThrow(InvalidIdentityException::class);
});

it('keeps identity methods and service casts final', function (): void {
    foreach ([RoleGrant::class, PermissionGrant::class, Permission::class] as $class) {
        $reflection = new ReflectionClass($class);
        foreach (['panel', 'tenantRef', 'permissionKey', 'roleKey', 'subjectRef', 'assignmentScopeRef', 'origin', 'actorRef', 'expiresAt', 'casts'] as $method) {
            if ($reflection->hasMethod($method)) {
                expect($reflection->getMethod($method)->isFinal())->toBeTrue();
            }
        }
    }
});

it('uses the selected storage host key grammar after hydration', function (): void {
    $registry = app(StorageRegistry::class);
    $storage = new Storage('numeric', $registry->get('default')->connection(), 'num_', 'bigint');
    $registry->register($storage);
    app(StorageSchema::class)->create('numeric');

    try {
        $role = $storage->model('role_grant')->newFromBuilder(['subject_type' => 'user', 'subject_id' => '007']);
        expect($role->storage())->toBe($storage)
            ->and(fn () => $role->subjectRef())->toThrow(InvalidIdentityException::class);
        $role->setRawAttributes(['subject_type' => 'user', 'subject_id' => 7]);
        expect($role->subjectRef()->id())->toBe('7');
    } finally {
        app(StorageSchema::class)->drop('numeric');
    }
});
