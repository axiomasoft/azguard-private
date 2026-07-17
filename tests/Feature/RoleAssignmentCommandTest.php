<?php

declare(strict_types=1);

use AzGuard\Events\RoleAttached;
use AzGuard\Events\RoleDetached;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;

describe('guard:role command', function (): void {

    it('assigns a role to a user by ID', function (): void {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => $user->id,
            'role' => 'editor',
        ])
            ->expectsOutputToContain('assigned')
            ->assertExitCode(0);

        expect($user->fresh()->hasRole('editor'))->toBeTrue();
    });

    it('detaches a role from a user by ID', function (): void {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor', 'level' => 5]);
        $user->assignRole($role);

        $this->artisan('guard:role', [
            'action' => 'detach',
            'user' => $user->id,
            'role' => 'editor',
        ])
            ->expectsOutputToContain('detached')
            ->assertExitCode(0);

        expect($user->fresh()->hasRole('editor'))->toBeFalse();
    });

    it('resolves the user by email', function (): void {
        $user = User::factory()->create(['email' => 'assignee@example.com']);
        Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => 'assignee@example.com',
            'role' => 'editor',
        ])->assertExitCode(0);

        expect($user->fresh()->hasRole('editor'))->toBeTrue();
    });

    it('resolves the role by class_name', function (): void {
        $user = User::factory()->create();
        Role::create([
            'name' => 'project-editor',
            'class_name' => ProjectEditorRole::class,
            'level' => 5,
        ]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => $user->id,
            'role' => ProjectEditorRole::class,
        ])->assertExitCode(0);

        expect($user->fresh()->hasRole('project-editor'))->toBeTrue();
    });

    it('fires RoleAttached on assign', function (): void {
        Event::fake([RoleAttached::class, RoleDetached::class]);

        $user = User::factory()->create();
        Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => $user->id,
            'role' => 'editor',
        ])->assertExitCode(0);

        Event::assertDispatched(RoleAttached::class);
    });

    it('fires RoleDetached on detach', function (): void {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor', 'level' => 5]);
        $user->assignRole($role);

        Event::fake([RoleAttached::class, RoleDetached::class]);

        $this->artisan('guard:role', [
            'action' => 'detach',
            'user' => $user->id,
            'role' => 'editor',
        ])->assertExitCode(0);

        Event::assertDispatched(RoleDetached::class);
    });

    it('fails for an unknown action', function (): void {
        $user = User::factory()->create();
        Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'grant',
            'user' => $user->id,
            'role' => 'editor',
        ])
            ->expectsOutputToContain('Unknown action')
            ->assertExitCode(1);
    });

    it('fails for an unknown user', function (): void {
        Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => '99999',
            'role' => 'editor',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails for an unknown role', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => $user->id,
            'role' => 'ghost',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('honors the --model option for the user FQCN', function (): void {
        $user = User::factory()->create();
        Role::create(['name' => 'editor', 'level' => 5]);

        $this->artisan('guard:role', [
            'action' => 'assign',
            'user' => $user->id,
            'role' => 'editor',
            '--model' => User::class,
        ])->assertExitCode(0);

        expect($user->fresh()->hasRole('editor'))->toBeTrue();
    });
});
