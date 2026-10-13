<?php

declare(strict_types=1);

use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\Internal\Storage;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Internal\WriteGuardedBuilder;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Storage\AdminRoleGrant;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/*
 * The environment is not a dimension of the matrices: no guard reads it. One test below runs every method and kind in
 * `local`, `testing` and `production` against real rows, which is where a guard registered per environment would show.
 *
 * The guards refuse a write before any SQL (the first matrix asserts zero queries), so the matrices run on a model
 * bound to the storage without a schema. The database is needed to prove that a refused write changes nothing and that
 * `mutate()` still writes: those tests build the world once, not once per case.
 */
beforeEach(function (): void {
    $this->storage = app(StorageRegistry::class)->get('default');
});

/** A model of the kind bound to the storage, as a row read from the database would be, without reading it. */
function loadedModel(Storage $storage, string $kind, string $class): Model
{
    $table = $storage->prefix().$kind.'s';

    return (new $class)->bindToStorage($storage, $table)->newFromBuilder(['id' => 1, 'origin' => 'manual', 'expires_at' => null]);
}

function directWritesWorld(Storage $storage): void
{
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    $storage->mutate('admin', function (StorageMutation $mutation): void {
        $mutation->table('role_grants')->insert(SchemaAssertions::grant());
        $mutation->table('permission_grants')->insert(SchemaAssertions::grant('permission'));
        $mutation->table('permissions')->insert(['panel' => 'admin', 'tenant_key' => 'global', 'name' => 'posts.view']);
        $mutation->touch('admin');
    });
}

/** @return array<string, array{string, list<mixed>}> */
function guardedModelMethods(): array
{
    return [
        'save' => ['save', []], 'saveQuietly' => ['saveQuietly', []], 'saveOrFail' => ['saveOrFail', []],
        'saveOrIgnore' => ['saveOrIgnore', []], 'update' => ['update', [['origin' => 'import']]],
        'updateQuietly' => ['updateQuietly', [['origin' => 'import']]], 'updateOrFail' => ['updateOrFail', [['origin' => 'import']]],
        'push' => ['push', []], 'pushQuietly' => ['pushQuietly', []], 'delete' => ['delete', []],
        'deleteQuietly' => ['deleteQuietly', []], 'deleteOrFail' => ['deleteOrFail', []], 'forceDelete' => ['forceDelete', []],
        'touch' => ['touch', []], 'touchQuietly' => ['touchQuietly', []],
        'increment' => ['increment', ['id']], 'decrement' => ['decrement', ['id']],
        'incrementQuietly' => ['incrementQuietly', ['id']], 'decrementQuietly' => ['decrementQuietly', ['id']],
        'incrementEach' => ['incrementEach', [['id' => 1]]], 'decrementEach' => ['decrementEach', [['id' => 1]]],
        'incrementEachQuietly' => ['incrementEachQuietly', [['id' => 1]]], 'decrementEachQuietly' => ['decrementEachQuietly', [['id' => 1]]],
    ];
}

dataset('guarded model methods', guardedModelMethods());

dataset('grant model kinds', [
    ['role_grant', RoleGrant::class], ['role_grant', AdminRoleGrant::class],
    ['permission_grant', PermissionGrant::class], ['permission', Permission::class],
]);

it('rejects instance writes before events and SQL', function (string $method, array $arguments, string $kind, string $class): void {
    if (! method_exists(Model::class, $method)) {
        expect((new ReflectionClass($class))->hasMethod($method))->toBeTrue();

        return;
    }
    $model = loadedModel($this->storage, $kind, $class);
    $model->setAttribute('expires_at', '2030-01-01');
    $queries = count($this->storage->connection()->pretend(function () use ($model, $method, $arguments): void {
        try {
            $model->$method(...$arguments);
            test()->fail('Direct write was allowed.');
        } catch (UnsupportedDirectWriteException $error) {
            expect($error->code())->toBe('unsupported_direct_write')->and($error->getMessage())->toContain($model::class, 'mutate');
        }
    }));
    expect($queries)->toBe(0)->and((new ReflectionMethod($class, $method))->isFinal())->toBeTrue();
})->with('guarded model methods')->with('grant model kinds');

/** @return array<string, array{string, list<mixed>}> */
function guardedBuilderMethods(): array
{
    return [
        'insert' => ['insert', [[['origin' => 'import']]]], 'insertOrIgnore' => ['insertOrIgnore', [[['origin' => 'import']]]],
        'insertOrIgnoreReturning' => ['insertOrIgnoreReturning', [[['origin' => 'import']]]],
        'insertGetId' => ['insertGetId', [['origin' => 'import']]], 'insertUsing' => ['insertUsing', [['origin'], 'select 1']],
        'insertOrIgnoreUsing' => ['insertOrIgnoreUsing', [['origin'], 'select 1']],
        'upsert' => ['upsert', [[['origin' => 'import']], ['id']]], 'update' => ['update', [['origin' => 'import']]],
        'updateFrom' => ['updateFrom', [['origin' => 'import']]],
        'updateOrInsert' => ['updateOrInsert', [['id' => 1], ['origin' => 'import']]],
        'increment' => ['increment', ['id']], 'decrement' => ['decrement', ['id']],
        'incrementEach' => ['incrementEach', [['id' => 1]]], 'decrementEach' => ['decrementEach', [['id' => 1]]],
        'delete' => ['delete', []], 'forceDelete' => ['forceDelete', []], 'truncate' => ['truncate', []],
        'create' => ['create', [['origin' => 'import']]], 'forceCreate' => ['forceCreate', [['origin' => 'import']]],
        'firstOrCreate' => ['firstOrCreate', [['id' => 1]]], 'updateOrCreate' => ['updateOrCreate', [['id' => 1], ['origin' => 'import']]],
        'createOrFirst' => ['createOrFirst', [['id' => 1]]], 'createQuietly' => ['createQuietly', [['id' => 1]]],
        'forceCreateQuietly' => ['forceCreateQuietly', [['id' => 1]]], 'incrementOrCreate' => ['incrementOrCreate', [['id' => 1]]],
        'touch' => ['touch', []],
    ];
}

dataset('guarded builder methods', guardedBuilderMethods());

it('rejects all bulk and create writes even when firstOrCreate could read an existing row', function (string $method, array $arguments, string $kind, string $class): void {
    $builder = loadedModel($this->storage, $kind, $class)->newQuery();
    expect(fn () => $builder->$method(...$arguments))->toThrow(UnsupportedDirectWriteException::class);
})->with('guarded builder methods')->with('grant model kinds');

it('guards every installed query builder write method before forwarding', function (): void {
    foreach ((new ReflectionClass(QueryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! preg_match('/^(insert|upsert|update|increment|decrement|delete|truncate)/', $method->getName())) {
            continue;
        }
        $guard = new ReflectionMethod(WriteGuardedBuilder::class, $method->getName());
        expect($guard->getDeclaringClass()->getName())->toBe(WriteGuardedBuilder::class)
            ->and($guard->isFinal())->toBeTrue();
    }
});

it('leaves the rows and the state untouched after every refused write in every environment', function (): void {
    directWritesWorld($this->storage);
    $attempts = 0;

    foreach (['local', 'testing', 'production'] as $environment) {
        app()->detectEnvironment(fn () => $environment);

        foreach ([['role_grant', RoleGrant::class], ['role_grant', AdminRoleGrant::class], ['permission_grant', PermissionGrant::class], ['permission', Permission::class]] as [$kind, $class]) {
            foreach (guardedBuilderMethods() as [$method, $arguments]) {
                expect(fn () => $this->storage->model($kind, $class)->newQuery()->$method(...$arguments))->toThrow(UnsupportedDirectWriteException::class);
                $attempts++;
            }

            foreach (guardedModelMethods() as [$method, $arguments]) {
                if (! method_exists(Model::class, $method)) {
                    continue;
                }
                $model = $this->storage->model($kind, $class)->newQuery()->firstOrFail();
                $model->setAttribute('expires_at', '2030-01-01');
                expect(fn () => $model->$method(...$arguments))->toThrow(UnsupportedDirectWriteException::class);
                $attempts++;
            }
        }
    }

    expect($attempts)->toBeGreaterThan(0)
        ->and($this->storage->model('role_grant')->newQuery()->count())->toBe(1)
        ->and($this->storage->model('permission_grant')->newQuery()->count())->toBe(1)
        ->and($this->storage->model('permission')->newQuery()->count())->toBe(1)
        ->and($this->storage->state('admin')->version)->toBe(1);
});

it('cannot bypass writes by disabling events or by fill save and allows mutate', function (): void {
    directWritesWorld($this->storage);
    $model = $this->storage->model('role_grant')->newQuery()->firstOrFail();
    expect(fn () => RoleGrant::withoutEvents(fn () => $model->save()))->toThrow(UnsupportedDirectWriteException::class)
        ->and(fn () => $model->forceFill(['expires_at' => '2030-01-01'])->save())->toThrow(UnsupportedDirectWriteException::class)
        ->and(fn () => $model->fill([])->save())->toThrow(UnsupportedDirectWriteException::class)
        ->and(fn () => $model->newQuery()->getModel()::destroy($model->getKey()))->toThrow(UnsupportedDirectWriteException::class);
    $this->storage->mutate('admin', function (StorageMutation $mutation): void {
        $mutation->table('role_grants')->update(['origin' => 'import']);
        $mutation->touch('admin');
    });
    expect($model->newQuery()->firstOrFail()->origin())->toBe('import')->and($this->storage->state('admin')->version)->toBe(2);
});
