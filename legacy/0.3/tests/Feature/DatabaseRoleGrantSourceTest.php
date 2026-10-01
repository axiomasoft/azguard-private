<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Registry\Sources\DatabaseRoleGrantSource;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns empty set when user has no DB roles', function () {
    $user = User::factory()->create();
    $source = app(DatabaseRoleGrantSource::class);

    $result = $source->permissionsFor($user, 'app');

    expect($result->isEmpty())->toBeTrue();
});

it('returns permissions assigned to user via DB role', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor', 'panel_id' => 'app']);

    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        'role_id' => $role->getKey(),
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole('editor');
    $user->flushPermissions('app');

    $source = app(DatabaseRoleGrantSource::class);
    $result = $source->permissionsFor($user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('app.posts.delete'))->toBeFalse();
});

it('aggregates permissions across multiple DB roles', function () {
    $user = User::factory()->create();
    $editor = Role::create(['name' => 'editor', 'panel_id' => 'app']);
    $viewer = Role::create(['name' => 'viewer', 'panel_id' => 'app']);

    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        ['role_id' => $editor->getKey(), 'panel_id' => 'app', 'permission_key' => 'app.posts.edit', 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $viewer->getKey(), 'panel_id' => 'app', 'permission_key' => 'app.posts.view', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $user->assignRole('editor');
    $user->assignRole('viewer');
    $user->flushPermissions('app');

    $source = app(DatabaseRoleGrantSource::class);
    $result = $source->permissionsFor($user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('app.posts.view'))->toBeTrue()
        ->and($result->grants('app.posts.delete'))->toBeFalse();
});

it('wildcard role grants all permissions', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'superadmin', 'panel_id' => 'app']);

    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        'role_id' => $role->getKey(),
        'panel_id' => 'app',
        'permission_key' => '*',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole('superadmin');
    $user->flushPermissions('app');

    $source = app(DatabaseRoleGrantSource::class);
    $result = $source->permissionsFor($user, 'app');

    expect($result->grants('anything.at.all'))->toBeTrue();
});

it('does not return permissions for other panels', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'admin-editor', 'panel_id' => 'admin']);

    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        'role_id' => $role->getKey(),
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.delete',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole('admin-editor');
    $user->flushPermissions('app');

    $source = app(DatabaseRoleGrantSource::class);
    $result = $source->permissionsFor($user, 'app');

    expect($result->grants('admin.users.delete'))->toBeFalse();
});

it('has priority 90', function () {
    expect(app(DatabaseRoleGrantSource::class)->priority())->toBe(90);
});
