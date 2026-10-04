<?php

declare(strict_types=1);

use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

class StorageCustomRoleGrant extends RoleGrant
{
    protected $casts = ['meta' => AsArrayObject::class, 'subject_id' => 'integer', 'score' => 'integer'];
}

class StorageForeignTableParent extends RoleGrant
{
    protected $table = 'other';
}

class StorageForeignTableChild extends StorageForeignTableParent {}

class StorageForeignConnectionRoleGrant extends RoleGrant
{
    protected $connection = 'secondary';
}

class StorageForeignConnectionChild extends StorageForeignConnectionRoleGrant {}

abstract class StorageAbstractRoleGrant extends RoleGrant {}

#[Table('other')]
class StorageAttributeTableParent extends RoleGrant {}

class StorageAttributeTableChild extends StorageAttributeTableParent {}

#[Connection('secondary')]
class StorageAttributeConnectionParent extends RoleGrant {}

class StorageAttributeConnectionChild extends StorageAttributeConnectionParent {}

#[Table('azg_role_grants')]
#[Connection('testbench')]
class StorageMatchingAttributes extends RoleGrant {}

it('preserves service casts and inherited storage binding on custom hydration', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $model = $storage->model('role_grant', StorageCustomRoleGrant::class);
    $hydrated = $model->newFromBuilder(['panel' => 'admin', 'subject_type' => 'user', 'subject_id' => '007', 'meta' => '{"a":1}', 'score' => '3']);

    expect($hydrated)->toBeInstanceOf(StorageCustomRoleGrant::class)
        ->and($hydrated->getTable())->toBe('azg_role_grants')
        ->and($hydrated->getConnectionName())->toBe('testbench')
        ->and($hydrated->storage())->toBe($storage)
        ->and($hydrated->meta)->toBe(['a' => 1])
        ->and($hydrated->subject_id)->toBe('007')
        ->and($hydrated->subjectRef()->id())->toBe('007')
        ->and($hydrated->score)->toBe(3);
});

it('rejects incompatible and inherited table or connection declarations', function (string $class): void {
    expect(fn () => app(StorageRegistry::class)->get('default')->model('role_grant', $class))->toThrow(StorageMismatchException::class);
})->with([Model::class, PermissionGrant::class, Permission::class, StorageForeignTableParent::class,
    StorageForeignTableChild::class, StorageForeignConnectionRoleGrant::class, StorageForeignConnectionChild::class,
    StorageAbstractRoleGrant::class]);

it('validates Laravel table and connection attributes including parents', function (): void {
    if (! class_exists(Table::class) || ! class_exists(Connection::class)) {
        $this->markTestSkipped('Eloquent Table/Connection attributes require Laravel 13; running '.app()->version().'.');
    }
    $storage = app(StorageRegistry::class)->get('default');
    foreach ([StorageAttributeTableParent::class, StorageAttributeTableChild::class, StorageAttributeConnectionParent::class, StorageAttributeConnectionChild::class] as $class) {
        expect(fn () => $storage->model('role_grant', $class))->toThrow(StorageMismatchException::class);
    }
    expect($storage->model('role_grant', StorageMatchingAttributes::class))->toBeInstanceOf(StorageMatchingAttributes::class);
});

it('requires binding and a known model kind', function (): void {
    expect(fn () => (new RoleGrant)->storage())->toThrow(StorageMismatchException::class)
        ->and(fn () => app(StorageRegistry::class)->get('default')->model('unknown'))->toThrow(StorageMismatchException::class);
});

it('binds each factory instance to its own connection and prefix', function (): void {
    $registry = app(StorageRegistry::class);
    $storage = new Storage('other', app('db')->connection('secondary'), 'other_');
    $registry->register($storage);
    app(StorageSchema::class)->create('other');

    try {
        $custom = $storage->model('role_grant', StorageCustomRoleGrant::class)->newInstance();
        $default = $registry->get('default')->model('role_grant', StorageCustomRoleGrant::class)->newInstance();
        expect($custom->getTable())->toBe('other_role_grants')
            ->and($custom->getConnectionName())->toBe('secondary')
            ->and($custom->storage())->toBe($storage)
            ->and($default->getTable())->toBe('azg_role_grants')
            ->and($default->getConnectionName())->toBe('testbench');
    } finally {
        app(StorageSchema::class)->drop('other');
    }
});
