<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Artisan;

it('lists GateAbility permissions discovered from the panel policy path', function (): void {
    Artisan::call('guard:list-permissions');

    expect(Artisan::output())
        ->toContain('Panel:')
        ->toContain('test.post.view')
        ->toContain('PostPolicy::canView');
});

it('skips panels that do not match the filter', function (): void {
    Artisan::call('guard:list-permissions', ['panel' => 'missing']);

    expect(Artisan::output())->not->toContain('test.post.view');
});

it('warns when a panel has no base path or namespace', function (): void {
    AzGuard::panel('test')?->basePath('')->namespace('');

    Artisan::call('guard:list-permissions', ['panel' => 'test']);

    expect(Artisan::output())->toContain('basePath/namespace not set');
});

it('finds a role by exact name and checks a DB permission', function (): void {
    $role = Role::query()->create(['name' => 'coverage-editor', 'level' => 1]);
    RolePermission::query()->create([
        'role_id' => $role->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect(Role::findByName('coverage-editor')?->is($role))->toBeTrue()
        ->and($role->hasDbPermission('test.post.view', 'test'))->toBeTrue()
        ->and($role->hasDbPermission('test.post.edit', 'test'))->toBeFalse()
        ->and($role->users()->pluck('id')->all())->toContain($user->id);
});
