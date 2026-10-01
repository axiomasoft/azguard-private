<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\DirectGrantResource\Pages\CreateDirectGrant;
use AzGuard\Filament\Resources\DirectGrantResource\Pages\ListDirectGrants;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Filament\Resources\RoleResource\Pages\CreateRole;
use AzGuard\Filament\Resources\RoleResource\Pages\EditRole;
use AzGuard\Filament\Resources\RoleResource\Pages\ListRoles;
use AzGuard\Filament\Resources\RoleResource\RelationManagers\RolePermissionsRelationManager;
use AzGuard\Filament\Resources\RoleResource\RelationManagers\RoleUsersRelationManager;
use AzGuard\Models\DirectGrant;
use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Tests\Stubs\CustomDirectGrant;
use AzGuard\Tests\Stubs\CustomRole;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function filamentRevision(): int
{
    return app(PermissionStateRevision::class)->current();
}

it('rejects an expired grant in the create page instead of turning it into a permanent grant', function (): void {
    $user = User::factory()->create();
    $method = new ReflectionMethod(CreateDirectGrant::class, 'handleRecordCreation');
    $data = [
        'grantable_id' => $user->getKey(),
        'panel_id' => 'admin',
        'permission_key' => 'admin.project.view_any',
        'expires_at' => now()->subSecond()->toDateTimeString(),
    ];

    expect(fn () => $method->invoke(new CreateDirectGrant, $data))
        ->toThrow(ValidationException::class);
    expect(DirectGrant::query()->count())->toBe(0);
});

it('revises direct-grant single and bulk revokes seen by a warmed reader', function (): void {
    $user = User::factory()->create();
    $grant = AzGuard::forUser($user)->on('admin')->grant('admin.project.view_any');
    $table = DirectGrantResource::table(Table::make(new ListDirectGrants));

    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();
    $before = filamentRevision();
    expect($table->getActions()[0]->process(null, ['record' => $grant]))->toBeTrue();
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();

    $first = AzGuard::forUser($user)->on('admin')->grant('admin.project.view_any');
    $second = AzGuard::forUser($user)->on('admin')->grant('admin.project.create');
    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();
    $before = filamentRevision();
    $table->getBulkActions()[0]->process(null, ['records' => $first->newCollection([$first, $second])]);

    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse()
        ->and($user->hasPermission('admin.project.create', 'admin'))->toBeFalse();
});

it('rolls a Filament grant revoke back if the revision row is missing', function (): void {
    $user = User::factory()->create();
    $grant = AzGuard::forUser($user)->on('admin')->grant('admin.project.view_any');
    $table = DirectGrantResource::table(Table::make(new ListDirectGrants));
    DB::table(Config::permissionStateTable())->delete();

    expect(fn () => $table->getActions()[0]->process(null, ['record' => $grant]))
        ->toThrow(RuntimeException::class, 'permission-state row is missing');
    expect($grant->newQuery()->whereKey($grant)->exists())->toBeTrue();
});

it('deletes a configured direct-grant model through its own model events', function (): void {
    config(['az-guard.models.direct_grant' => CustomDirectGrant::class]);
    $user = User::factory()->create();
    $deleted = [];
    CustomDirectGrant::deleted(static function (CustomDirectGrant $grant) use (&$deleted): void {
        $deleted[] = $grant->getKey();
    });
    $grant = CustomDirectGrant::query()->create([
        'grantable_type' => $user->getMorphClass(),
        'grantable_id' => $user->getKey(),
        'panel_id' => 'admin',
        'permission_key' => 'custom.view',
    ]);
    $table = DirectGrantResource::table(Table::make(new ListDirectGrants));
    $before = filamentRevision();

    expect($table->getActions()[0]->process(null, ['record' => $grant]))->toBeTrue();
    expect($deleted)->toBe([$grant->getKey()])
        ->and(filamentRevision())->toBe($before + 1);
});

it('routes relation permission deletes through the synchronizer', function (): void {
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'viewer', 'level' => 1]);
    $first = $role->dbPermissions()->create(['panel_id' => 'admin', 'permission_key' => 'admin.project.view_any']);
    $second = $role->dbPermissions()->create(['panel_id' => 'admin', 'permission_key' => 'admin.project.create']);
    $role->dbPermissions()->create(['panel_id' => 'admin', 'permission_key' => 'dynamic.hidden']);
    $user->assignRole($role);
    $manager = new RolePermissionsRelationManager;
    $manager->ownerRecord = $role;
    $table = $manager->table(Table::make($manager));

    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();
    $before = filamentRevision();
    expect($table->getActions()[0]->process(null, ['record' => $first]))->toBeTrue();
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();

    $before = filamentRevision();
    $table->getBulkActions()[0]->process(null, ['records' => $second->newCollection([$second])]);
    expect(filamentRevision())->toBe($before + 1)
        ->and($role->dbPermissions()->pluck('permission_key')->all())->toBe(['dynamic.hidden']);
});

it('routes relation user attach and detach through subject mutations', function (): void {
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'viewer', 'level' => 1]);
    $role->dbPermissions()->create(['panel_id' => 'admin', 'permission_key' => 'admin.project.view_any']);
    $manager = new RoleUsersRelationManager;
    $manager->ownerRecord = $role;
    $table = $manager->table(Table::make($manager));

    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();
    $before = filamentRevision();
    $table->getHeaderActions()[0]->record($user)->process(null);
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();

    $before = filamentRevision();
    $table->getActions()[0]->process(null, ['record' => $user]);
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();

    $user->assignRole($role);
    $before = filamentRevision();
    $table->getBulkActions()[0]->process(null, ['records' => $user->newCollection([$user])]);
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();
});

it('revises role deletes and rejects a bulk delete when the revision cannot advance', function (): void {
    $user = User::factory()->create();
    $first = Role::query()->create(['name' => 'first', 'level' => 1]);
    $second = Role::query()->create(['name' => 'second', 'level' => 1]);
    $first->dbPermissions()->create(['panel_id' => 'admin', 'permission_key' => 'admin.project.view_any']);
    $user->assignRole($first);
    $table = RoleResource::table(Table::make(new ListRoles));

    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();
    $before = filamentRevision();
    expect($table->getActions()[1]->process(null, ['record' => $first]))->toBeTrue();
    expect(filamentRevision())->toBe($before + 1)
        ->and($user->hasPermission('admin.project.view_any', 'admin'))->toBeFalse();

    $third = Role::query()->create(['name' => 'third', 'level' => 1]);
    DB::table(Config::permissionStateTable())->delete();
    expect(fn () => $table->getBulkActions()[0]->process(null, ['records' => $second->newCollection([$second, $third])]))
        ->toThrow(RuntimeException::class, 'permission-state row is missing');
    expect(Role::query()->whereIn('name', ['second', 'third'])->count())->toBe(2);
});

it('creates and edits configured roles with class_name in one revisioned save and deletes through their model event', function (): void {
    config(['az-guard.models.role' => CustomRole::class]);
    CustomRole::$created = false;
    $savedClassNames = [];
    $deleted = [];
    CustomRole::saved(static function (CustomRole $role) use (&$savedClassNames): void {
        $savedClassNames[] = $role->class_name;
    });
    CustomRole::deleted(static function (CustomRole $role) use (&$deleted): void {
        $deleted[] = $role->getKey();
    });
    $before = filamentRevision();
    $record = (new ReflectionMethod(CreateRole::class, 'handleRecordCreation'))
        ->invoke(new CreateRole, ['name' => 'manager', 'level' => 5, 'class_name' => ManagerRole::class]);

    expect($record)->toBeInstanceOf(CustomRole::class)
        ->and(CustomRole::$created)->toBeTrue()
        ->and($savedClassNames)->toBe([ManagerRole::class])
        ->and($record->fresh()->class_name)->toBe(ManagerRole::class)
        ->and(filamentRevision())->toBe($before + 1);

    $before = filamentRevision();
    (new ReflectionMethod(EditRole::class, 'handleRecordUpdate'))
        ->invoke(new EditRole, $record, ['name' => 'manager', 'level' => 7, 'class_name' => null]);
    expect($record->fresh()->level)->toBe(7)
        ->and($record->fresh()->class_name)->toBeNull()
        ->and($savedClassNames)->toBe([ManagerRole::class, null])
        ->and(filamentRevision())->toBe($before + 1);

    $before = filamentRevision();
    (new ReflectionMethod(EditRole::class, 'handleRecordUpdate'))
        ->invoke(new EditRole, $record, ['name' => 'manager', 'level' => 7, 'class_name' => null]);
    expect(filamentRevision())->toBe($before)
        ->and($savedClassNames)->toBe([ManagerRole::class, null]);

    $before = filamentRevision();
    $table = RoleResource::table(Table::make(new ListRoles));
    expect($table->getActions()[1]->process(null, ['record' => $record]))->toBeTrue();
    expect($deleted)->toBe([$record->getKey()])
        ->and(filamentRevision())->toBe($before + 1);
});

it('rolls role creation back when revision bump fails', function (): void {
    DB::table(Config::permissionStateTable())->delete();

    expect(fn () => (new ReflectionMethod(CreateRole::class, 'handleRecordCreation'))
        ->invoke(new CreateRole, ['name' => 'manager', 'level' => 5, 'class_name' => ManagerRole::class]))
        ->toThrow(RuntimeException::class, 'permission-state row is missing');
    expect(Role::query()->where('name', 'manager')->exists())->toBeFalse();
});
