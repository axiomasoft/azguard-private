<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\User;

describe('guard:list-scoped-roles command', function (): void {

    it('shows warning when user has no scoped roles', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:list-scoped-roles', ['user' => $user->id])
            ->expectsOutputToContain('has no scoped roles')
            ->assertExitCode(0);
    });

    it('lists scoped roles for user by ID', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'project-editor',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);

        $this->artisan('guard:list-scoped-roles', ['user' => $user->id])
            ->expectsOutputToContain('project-editor')
            ->expectsOutputToContain('Project')
            ->assertExitCode(0);
    });

    it('lists scoped roles for user by email', function (): void {
        $user = User::factory()->create(['email' => 'editor@example.com']);
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'project-editor-email',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);

        $this->artisan('guard:list-scoped-roles', ['user' => 'editor@example.com'])
            ->expectsOutputToContain('project-editor-email')
            ->assertExitCode(0);
    });

    it('returns failure for unknown user', function (): void {
        $this->artisan('guard:list-scoped-roles', ['user' => '99999'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('filters by entity type via --entity option', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'filtered-editor',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole($role, $project);

        // Filter matches — should show the role
        $this->artisan('guard:list-scoped-roles', [
            'user' => $user->id,
            '--entity' => Project::class,
        ])
            ->expectsOutputToContain('filtered-editor')
            ->assertExitCode(0);

        // Filter does NOT match — should warn no scoped roles
        $this->artisan('guard:list-scoped-roles', [
            'user' => $user->id,
            '--entity' => 'App\\Models\\Team',
        ])
            ->expectsOutputToContain('has no scoped roles')
            ->assertExitCode(0);
    });
});
