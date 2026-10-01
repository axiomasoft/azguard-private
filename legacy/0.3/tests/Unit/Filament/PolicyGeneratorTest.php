<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Permissions\PermissionSubject;
use AzGuard\Filament\Permissions\PolicyGenerator;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;

it('names a policy after the backing model and emits record-level signatures', function (): void {
    $subject = new PermissionSubject(
        name: 'Project',
        label: 'Projects',
        abilities: ['view_any', 'view', 'create', 'update'],
        model: Project::class,
    );

    $source = (new PolicyGenerator)->source(
        subject: $subject,
        panelId: 'admin',
        schema: new PermissionSchema,
        namespace: 'App\\Policies',
        userModel: User::class,
    );

    expect((new PolicyGenerator)->className($subject))->toBe('ProjectPolicy')
        ->and($source)->toContain('class ProjectPolicy')
        ->and($source)->toContain('public function viewAny(User $user): bool')
        ->and($source)->toContain('public function view(User $user, Project $record): bool')
        ->and($source)->toContain("hasPermission('admin.project.view', 'admin')");
});

it('falls back to the subject name when the resource has no model', function (): void {
    $subject = new PermissionSubject(
        name: 'Dashboard',
        label: 'Dashboard',
        abilities: ['view'],
    );

    expect((new PolicyGenerator)->className($subject))->toBe('DashboardPolicy');
});
