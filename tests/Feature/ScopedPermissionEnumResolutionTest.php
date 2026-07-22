<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\EnumScopedRole;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\User;

/**
 * F3 — hasScopedPermission must resolve a role's declared enum cases through the
 * owning panel BEFORE the in_array match. Previously an enum-based scoped role
 * (permissions() -> list<UnitEnum>, the documented PREFERRED form) granted ZERO
 * scoped permissions: the resolved scoped string key ("test.post.view") was
 * compared against the raw [TestPermission::PostView] list, so in_array was
 * always false — a silent false-negative.
 */
describe('F3 — enum-based scoped roles resolve through the panel', function (): void {

    it('grants a scoped permission declared as an enum case (was silently denied)', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'enum-scoped-editor',
            'level' => 5,
        ], EnumScopedRole::class);

        $user->assignScopedRole($role, $project);

        // Checked with the enum case itself — panel resolved from the enum owner.
        expect($user->hasScopedPermission(TestPermission::PostView, $project))->toBeTrue();
        expect($user->hasScopedPermission(TestPermission::PostCreate, $project))->toBeTrue();
    });

    it('grants the same permission checked as its resolved string key', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'enum-scoped-editor-str',
            'level' => 5,
        ], EnumScopedRole::class);

        $user->assignScopedRole($role, $project);

        // TestPermission::PostView resolves to "test.post.view" on the 'test' panel.
        expect($user->hasScopedPermission('test.post.view', $project))->toBeTrue();
        expect($user->hasScopedPermission('test.post.create', $project))->toBeTrue();
    });

    it('does not grant an enum permission the role never declared', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'enum-scoped-partial',
            'level' => 5,
        ], EnumScopedRole::class);

        $user->assignScopedRole($role, $project);

        expect($user->hasScopedPermission(TestPermission::PostDelete, $project))->toBeFalse();
        expect($user->hasScopedPermission('test.post.delete', $project))->toBeFalse();
    });

    it('scopes the enum permission to the entity — other entities stay denied', function (): void {
        $user = User::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'enum-scoped-isolation',
            'level' => 5,
        ], EnumScopedRole::class);

        $user->assignScopedRole($role, $projectA);

        expect($user->hasScopedPermission(TestPermission::PostView, $projectA))->toBeTrue();
        expect($user->hasScopedPermission(TestPermission::PostView, $projectB))->toBeFalse();
    });

    it('keeps string-based scoped roles working (back-compat)', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'string-scoped-editor',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);

        expect($user->hasScopedPermission('projects.edit', $project))->toBeTrue();
        expect($user->hasScopedPermission('projects.view', $project))->toBeTrue();
        expect($user->hasScopedPermission('projects.delete', $project))->toBeFalse();
    });
});
