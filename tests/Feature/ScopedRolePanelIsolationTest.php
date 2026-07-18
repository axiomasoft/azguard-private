<?php

declare(strict_types=1);

use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\User;

/**
 * F8 — Scoped-role panel isolation.
 *
 * model_has_scopes gained a nullable panel_id so that a scope assigned under
 * panel A is NOT honoured when checked against panel B — matching the isolation
 * boundary DirectGrant and RolePermission already enforce. A scoped wildcard
 * ('*') is the sharpest probe: without the panel_id filter it would grant every
 * permission on every panel. A null panel_id stays "any panel" (back-compat).
 */
describe('F8 — scoped-role panel isolation via panel_id', function (): void {

    it('does NOT grant access in panel B for a scope assigned under panel A', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass([
            'name' => 'scoped-super-A',
            'level' => 5,
        ], SuperAdminRole::class); // permissions() => ['*']

        // Assigned strictly under panel 'test'. User has NO global role, so the
        // hasPermission() wildcard fallback cannot mask the scoped-only check.
        $user->assignScopedRole($role, $project, panelId: 'test');

        expect($user->hasScopedPermission('test.post.view', $project, 'test'))->toBeTrue();
        // Same wildcard scope, different panel — must be filtered out by panel_id.
        expect($user->hasScopedPermission('other.post.view', $project, 'other'))->toBeFalse();
    });

    it('honours a null-panel scope for ANY panel (back-compat)', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-super-null',
            'level' => 5,
        ], SuperAdminRole::class);

        // No panelId => persisted panel_id is null => any-panel.
        $user->assignScopedRole($role, $project);

        expect(ModelHasScope::query()->where('model_id', $user->getKey())->value('panel_id'))
            ->toBeNull();

        expect($user->hasScopedPermission('test.post.view', $project, 'test'))->toBeTrue();
        expect($user->hasScopedPermission('other.post.view', $project, 'other'))->toBeTrue();
    });

    it('assignScopedRole persists the given panel_id', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-super-persist',
            'level' => 5,
        ], SuperAdminRole::class);

        $user->assignScopedRole($role, $project, panelId: 'test');

        expect(
            ModelHasScope::query()
                ->where('model_id', $user->getKey())
                ->where('model_type', $user->getMorphClass())
                ->where('scope_entity_id', $project->getKey())
                ->where('scope_entity_type', $project->getMorphClass())
                ->where('role_id', $role->getKey())
                ->where('panel_id', 'test')
                ->exists(),
        )->toBeTrue();
    });

    it('isolates two panel-scoped assignments of the same role independently', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-super-two-panels',
            'level' => 5,
        ], SuperAdminRole::class);

        // firstOrCreate keys on panel_id, so 'test' and 'other' are distinct rows.
        $user->assignScopedRole($role, $project, panelId: 'test');
        $user->assignScopedRole($role, $project, panelId: 'other');

        expect(
            ModelHasScope::query()->where('model_id', $user->getKey())->count(),
        )->toBe(2);

        expect($user->hasScopedPermission('test.post.view', $project, 'test'))->toBeTrue();
        expect($user->hasScopedPermission('other.post.view', $project, 'other'))->toBeTrue();
    });

    it('hasScopedRole with an explicit panel matches its own panel and a null scope', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        createRoleWithClass(['name' => 'scoped-role-panel',
            'level' => 5,
        ], SuperAdminRole::class);

        $user->assignScopedRole('scoped-role-panel', $project, panelId: 'test');

        // Its own panel — matches.
        expect($user->hasScopedRole('scoped-role-panel', $project, 'test'))->toBeTrue();
        // A different panel — the 'test'-scoped row does NOT match panel 'other'.
        expect($user->hasScopedRole('scoped-role-panel', $project, 'other'))->toBeFalse();
        // No panel argument — matches regardless of the row's panel_id.
        expect($user->hasScopedRole('scoped-role-panel', $project))->toBeTrue();
    });

    it('removeScopedRole with a panel removes only that panel row', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-remove-panel',
            'level' => 5,
        ], SuperAdminRole::class);

        $user->assignScopedRole($role, $project, panelId: 'test');
        $user->assignScopedRole($role, $project, panelId: 'other');

        $user->removeScopedRole($role, $project, panelId: 'test');

        expect($user->hasScopedRole($role, $project, 'test'))->toBeFalse();
        expect(
            ModelHasScope::query()
                ->where('model_id', $user->getKey())
                ->where('panel_id', 'other')
                ->exists(),
        )->toBeTrue();
    });

    it('removeScopedRole with panelId=null removes only the any-panel row (Variant B, D10)', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-remove-null-only',
            'level' => 5,
        ], SuperAdminRole::class);

        $user->assignScopedRole($role, $project); // any-panel row (panel_id === null)
        $user->assignScopedRole($role, $project, panelId: 'test');
        $user->assignScopedRole($role, $project, panelId: 'other');

        $user->removeScopedRole($role, $project); // panelId defaults to null

        expect(
            ModelHasScope::query()->where('model_id', $user->getKey())->whereNull('panel_id')->exists(),
        )->toBeFalse();
        expect(
            ModelHasScope::query()->where('model_id', $user->getKey())->where('panel_id', 'test')->exists(),
        )->toBeTrue();
        expect(
            ModelHasScope::query()->where('model_id', $user->getKey())->where('panel_id', 'other')->exists(),
        )->toBeTrue();
    });

    it('removeScopedRoleEverywhere removes the assignment across every panel', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-remove-everywhere',
            'level' => 5,
        ], SuperAdminRole::class);

        $user->assignScopedRole($role, $project); // any-panel row
        $user->assignScopedRole($role, $project, panelId: 'test');
        $user->assignScopedRole($role, $project, panelId: 'other');

        expect(ModelHasScope::query()->where('model_id', $user->getKey())->count())->toBe(3);

        $user->removeScopedRoleEverywhere($role, $project);

        expect(ModelHasScope::query()->where('model_id', $user->getKey())->count())->toBe(0);
        expect($user->hasScopedRole($role, $project))->toBeFalse();
    });
});
