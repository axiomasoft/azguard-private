<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\PermissionEnumGenerator;
use AzGuard\Filament\Permissions\PermissionSubject;
use AzGuard\Tests\Stubs\Project;

it('builds a panel-agnostic permission enum from a discovered subject', function (): void {
    $subject = new PermissionSubject(
        name: 'Project',
        label: 'Projects',
        abilities: ['view_any', 'create'],
        model: Project::class,
    );
    $generator = new PermissionEnumGenerator;

    expect($generator->className($subject))->toBe('ProjectPermission')
        ->and($generator->source($subject, 'App\\Guards\\Admin'))
        ->toContain('enum ProjectPermission: string')
        ->and($generator->source($subject, 'App\\Guards\\Admin'))
        ->toContain("case ViewAny = 'project.view_any';")
        ->and($generator->source($subject, 'App\\Guards\\Admin'))
        ->toContain("case Create = 'project.create';")
        ->and($generator->source($subject, 'App\\Guards\\Admin'))
        ->not->toContain('admin.project');
});
