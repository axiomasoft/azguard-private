<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Exceptions\InvalidModelConfigException;
use AzGuard\Facades\AzGuard;
use AzGuard\Models\DirectGrant;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Sources\DatabaseRoleGrantSource;
use AzGuard\Tests\Stubs\CustomDirectGrant;
use AzGuard\Tests\Stubs\CustomModelHasScope;
use AzGuard\Tests\Stubs\CustomRole;
use AzGuard\Tests\Stubs\HiddenPermissionRolePermission;
use AzGuard\Tests\Stubs\OtherConnectionDirectGrant;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects a non-subclass model config with a named exception', function (): void {
    config(['az-guard.models.direct_grant' => stdClass::class]);

    expect(fn () => Config::directGrantModel())
        ->toThrow(InvalidModelConfigException::class, 'az-guard.models.direct_grant');
});

it('uses configured role model on syncRoles batch load', function (): void {
    config(['az-guard.models.role' => CustomRole::class]);
    CustomRole::$retrieved = false;

    $role = CustomRole::query()->create(['name' => 'editor', 'level' => 1]);
    $user = User::factory()->create();

    $user->syncRoles(['editor']);

    expect(CustomRole::$retrieved)->toBeTrue();
});

it('uses configured direct grant model on HasDirectGrants::grant', function (): void {
    config(['az-guard.models.direct_grant' => CustomDirectGrant::class]);
    CustomDirectGrant::$created = false;

    $user = UserWithDirectGrants::factory()->create();
    $user->grant('test.post.view', 'test');

    expect(CustomDirectGrant::$created)->toBeTrue();
});

it('uses configured direct grant model on GrantBuilder', function (): void {
    config(['az-guard.models.direct_grant' => CustomDirectGrant::class]);
    CustomDirectGrant::$created = false;

    $user = User::factory()->create();
    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    expect(CustomDirectGrant::$created)->toBeTrue();
});

it('uses configured scope model on scoped role reads', function (): void {
    config(['az-guard.models.scope' => CustomModelHasScope::class]);
    CustomModelHasScope::$queried = false;

    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::query()->create(['name' => 'editor', 'level' => 1]);

    $user->assignScopedRole('editor', $project);
    CustomModelHasScope::$queried = false;

    expect($user->hasScopedRole('editor', $project))->toBeTrue()
        ->and(CustomModelHasScope::$queried)->toBeTrue();
});

it('honours RolePermission global scopes in DatabaseRoleGrantSource', function (): void {
    config(['az-guard.models.role_permission' => HiddenPermissionRolePermission::class]);

    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor', 'level' => 1]);

    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        [
            'role_id' => $role->getKey(),
            'panel_id' => 'app',
            'permission_key' => 'app.posts.edit',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'role_id' => $role->getKey(),
            'panel_id' => 'app',
            'permission_key' => 'secret.hidden',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $user->assignRole('editor');
    $user->flushPermissions('app');

    $source = app(DatabaseRoleGrantSource::class);
    $result = $source->permissionsFor($user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('secret.hidden'))->toBeFalse();
});

it('reads role assignments from the shared non-default authorization connection', function (): void {
    $user = User::factory()->create();

    config([
        'database.connections.azguard_shared' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'az-guard.models.role' => SharedConnectionRole::class,
        'az-guard.models.scope' => SharedConnectionScope::class,
        'az-guard.models.direct_grant' => SharedConnectionDirectGrant::class,
        'az-guard.models.role_permission' => SharedConnectionRolePermission::class,
    ]);

    $connection = DB::connection('azguard_shared');
    $connection->getSchemaBuilder()->create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->integer('level');
        $table->string('class_name')->nullable();
        $table->timestamps();
    });
    $connection->getSchemaBuilder()->create('model_has_roles', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    $connection->getSchemaBuilder()->create('az_guard_role_permissions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('role_id');
        $table->string('panel_id');
        $table->string('permission_key');
        $table->timestamps();
    });

    $role = SharedConnectionRole::query()->create(['name' => 'shared-editor', 'level' => 1]);
    SharedConnectionRolePermission::query()->create([
        'role_id' => $role->getKey(),
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
    ]);
    $connection->table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ]);

    expect(DB::table('model_has_roles')->count())->toBe(0)
        ->and(app(DatabaseRoleGrantSource::class)->permissionsFor($user, 'app')->grants('app.posts.edit'))->toBeTrue();

    // Simulate a database without enforced foreign keys: an orphan pivot and
    // permission row must never keep a deleted role's grant alive.
    $connection->table('roles')->where('id', $role->getKey())->delete();

    expect(app(DatabaseRoleGrantSource::class)->permissionsFor($user, 'app')->isEmpty())->toBeTrue();
});

it('rejects split model connections before permission resolution', function (): void {
    config(['az-guard.models.direct_grant' => OtherConnectionDirectGrant::class]);

    $user = User::factory()->create();

    expect(fn () => app(EffectivePermissionResolver::class)->forUser($user, 'test'))
        ->toThrow(RuntimeException::class, 'split database connections');
});

it('rejects split authorization connections before mutation', function (): void {
    config([
        'database.connections.other' => config('database.connections.testbench'),
        'az-guard.models.direct_grant' => OtherConnectionDirectGrant::class,
    ]);

    $user = UserWithDirectGrants::factory()->create();

    try {
        $user->grant('test.post.view', 'test');
        expect(false)->toBeTrue('expected connection mismatch');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('split database connections');
    }
});

it('reports invalid model config via authorizationModelConfigErrors', function (): void {
    config(['az-guard.models.role' => 'Not\\A\\Class']);

    expect(Config::authorizationModelConfigErrors())
        ->not->toBeEmpty()
        ->and(Config::authorizationModelConfigErrors()[0])
        ->toContain('az-guard.models.role');
});

class SharedConnectionRole extends Role
{
    protected $connection = 'azguard_shared';
}

class SharedConnectionScope extends ModelHasScope
{
    protected $connection = 'azguard_shared';
}

class SharedConnectionDirectGrant extends DirectGrant
{
    protected $connection = 'azguard_shared';
}

class SharedConnectionRolePermission extends RolePermission
{
    protected $connection = 'azguard_shared';
}
