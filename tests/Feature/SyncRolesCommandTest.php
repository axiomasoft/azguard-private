<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\Role;
use AzGuard\Support\Panel;

it('creates roles for panel from PHP role classes', function (): void {
    // Ensure roles table is empty
    expect(Role::query()->count())->toBe(0);

    $this->artisan('guard:sync-roles')
        ->expectsOutputToContain('Sync complete')
        ->assertSuccessful();

    expect(Role::query()->count())->toBeGreaterThan(0);
});

it('filters by panel option', function (): void {
    $this->artisan('guard:sync-roles', ['--panel' => 'test'])
        ->expectsOutputToContain('Sync complete')
        ->assertSuccessful();
});

it('supports dry-run mode without writing to database', function (): void {
    AzGuard::registerPanel(
        panel: Panel::make()->id(id: 'test')->label(label: 'Test Panel'),
    );

    // Seed an existing role so the command finds something to sync.
    createRoleWithClass([
        'name' => 'existing-role',
        'level' => 10,
    ], 'AzGuard\\Tests\\Stubs\\Roles\\ExistingRole');

    $beforeCount = Role::query()->count();

    $this->artisan('guard:sync-roles', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] No changes will be written to the database.')
        ->expectsOutputToContain('Sync complete (dry-run)')
        ->assertSuccessful();

    $afterCount = Role::query()->count();

    expect($afterCount)->toBe($beforeCount);
});
