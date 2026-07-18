<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Models\Role;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;

/**
 * B-04 — panelId is widened to string|BackedEnum (and role to string|BackedEnum|Role)
 * across the model permission APIs, direct grants, scoped roles, role assignment,
 * AzGuardManager::isSuperAdmin and AzGuardPlugin::forPanel. Every enum-argument
 * call below must behave identically to its plain-string counterpart.
 */
enum TestPanelIdEnum: string
{
    case App = 'app';
}

enum TestRoleNameEnum: string
{
    case Manager = 'manager';
}

it('hasPermission()/isSuperAdmin()/permissionSet() accept an enum panelId', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'manager', 'level' => 0], ManagerRole::class);
    $user->assignRole($role);
    $user->load('roles');

    expect($user->hasPermission('test.post.view', TestPanelIdEnum::App))
        ->toBe($user->hasPermission('test.post.view', 'app'));

    expect($user->permissionSet(TestPanelIdEnum::App)->keys())
        ->toBe($user->permissionSet('app')->keys());
});

it('AzGuardManager::isSuperAdmin() accepts an enum panelId', function (): void {
    $role = createRoleWithClass(['name' => 'super-b04', 'level' => 0], SuperAdminRole::class);
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $actor->load('roles');

    $manager = new AzGuardManager;

    expect($manager->isSuperAdmin($actor, TestPanelIdEnum::App))
        ->toBe($manager->isSuperAdmin($actor, 'app'))
        ->toBeTrue();
});

it('AzGuardPlugin::forPanel() accepts an enum panelId', function (): void {
    expect(AzGuardPlugin::make()->forPanel(TestPanelIdEnum::App)->getPanelId())->toBe('app');
});

it('hasGrant()/grant()/revoke()/grants() accept an enum panelId', function (): void {
    $user = UserWithDirectGrants::factory()->create();

    $user->grant('app.reports.view', TestPanelIdEnum::App);

    expect($user->hasGrant('app.reports.view', TestPanelIdEnum::App))->toBeTrue()
        ->and($user->grants(TestPanelIdEnum::App)->pluck('permission_key')->all())->toBe(['app.reports.view']);

    $user->revoke('app.reports.view', TestPanelIdEnum::App);

    expect($user->hasGrant('app.reports.view', TestPanelIdEnum::App))->toBeFalse();
});

it('assignScopedRole()/hasScopedRole() accept an enum role and an enum panelId', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $role = Role::create(['name' => 'manager', 'level' => 1]);

    $user->assignScopedRole(TestRoleNameEnum::Manager, $project, panelId: TestPanelIdEnum::App);

    expect($user->hasScopedRole(TestRoleNameEnum::Manager, $project, TestPanelIdEnum::App))->toBeTrue();

    $user->removeScopedRole(TestRoleNameEnum::Manager, $project, panelId: TestPanelIdEnum::App);

    expect($user->hasScopedRole(TestRoleNameEnum::Manager, $project, TestPanelIdEnum::App))->toBeFalse();
});

it('assignRole()/removeRole() accept an enum role name', function (): void {
    $user = User::factory()->create();
    Role::create(['name' => 'manager', 'level' => 1]);

    $user->assignRole(TestRoleNameEnum::Manager);
    $user->load('roles');

    expect($user->hasRole('manager'))->toBeTrue();

    $user->removeRole(TestRoleNameEnum::Manager);
    $user->load('roles');

    expect($user->hasRole('manager'))->toBeFalse();
});
