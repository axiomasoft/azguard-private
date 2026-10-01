<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

describe('Gate integration — entity-scoped roles', function (): void {

    it('denies scoped permission when no scoped role is assigned', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        expect($user->hasScopedPermission('projects.edit', $project))->toBeFalse();
    });

    it('grants scoped permission via assigned scoped role', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'project-editor',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);

        expect($user->hasScopedPermission('projects.edit', $project))->toBeTrue();
        expect($user->hasScopedPermission('projects.view', $project))->toBeTrue();
    });

    it('superadmin wildcard bypasses scoped permission check', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'superadmin-scoped',
            'level' => 1000,
        ], SuperAdminRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        // SuperAdmin via global role — hasScopedPermission delegates to hasPermission first
        expect($user->hasScopedPermission('projects.edit', $project))->toBeTrue();
    });

    it('scoped role on project A does not grant access to project B', function (): void {
        $user = User::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'editor-isolation',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $projectA);

        expect($user->hasScopedPermission('projects.edit', $projectA))->toBeTrue();
        expect($user->hasScopedPermission('projects.edit', $projectB))->toBeFalse();
    });

    it('global Gate::allows still works alongside scoped roles', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $globalRole = createRoleWithClass(['name' => 'manager-combo',
            'level' => 10,
        ], ManagerRole::class);

        $scopedRole = createRoleWithClass(['name' => 'project-editor-combo',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->roles()->attach($globalRole);
        $user->load('roles');
        $user->assignScopedRole($scopedRole, $project);

        $this->actingAs($user);

        // Global Gate permission from ManagerRole
        expect(Gate::allows('test.post.view'))->toBeTrue();
        // Scoped permission from ProjectEditorRole
        expect($user->hasScopedPermission('projects.edit', $project))->toBeTrue();
        // Cross-check: global Gate does NOT accidentally expose scoped-only permission
        expect(Gate::allows('projects.edit'))->toBeFalse();
    });

    it('removeScopedRole revokes the permission', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'editor-revoke',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);
        expect($user->hasScopedPermission('projects.edit', $project))->toBeTrue();

        $user->removeScopedRole($role, $project);
        expect($user->hasScopedPermission('projects.edit', $project))->toBeFalse();
    });
});
