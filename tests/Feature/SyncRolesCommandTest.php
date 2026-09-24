<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\Role;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\CustomRole;
use AzGuard\Tests\Stubs\Roles\SalesAdminRole;
use AzGuard\Tests\Stubs\Roles\SupportAdminRole;
use AzGuard\Tests\Stubs\User;

function registerIdentityPanels(): void
{
    AzGuard::registerPanel(
        Panel::make()->id('sales')->label('Sales')->roleClasses([SalesAdminRole::class]),
    );
    AzGuard::registerPanel(
        Panel::make()->id('support')->label('Support')->roleClasses([SupportAdminRole::class]),
    );
}

it('creates roles for panel from PHP role classes', function (): void {
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

it('creates distinct panel-qualified rows for equal getName() classes', function (): void {
    registerIdentityPanels();

    $this->artisan('guard:sync-roles')->assertSuccessful();

    $sales = Role::query()->where('class_name', SalesAdminRole::class)->first();
    $support = Role::query()->where('class_name', SupportAdminRole::class)->first();

    expect($sales)->not->toBeNull()
        ->and($support)->not->toBeNull()
        ->and($sales->name)->toBe('sales:admin')
        ->and($support->name)->toBe('support:admin')
        ->and($sales->id)->not->toBe($support->id);

    $salesId = $sales->id;
    $supportId = $support->id;
    $revision = app(PermissionStateRevision::class)->current();

    $this->artisan('guard:sync-roles')->assertSuccessful();

    expect(Role::query()->where('class_name', SalesAdminRole::class)->value('id'))->toBe($salesId)
        ->and(Role::query()->where('class_name', SupportAdminRole::class)->value('id'))->toBe($supportId)
        ->and(app(PermissionStateRevision::class)->current())->toBe($revision);
});

it('renames a legacy unqualified code row in place and preserves assignments', function (): void {
    registerIdentityPanels();

    $legacy = createRoleWithClass(['name' => 'admin', 'level' => 7], SalesAdminRole::class);
    $user = User::factory()->create();
    $user->assignRole($legacy);

    $this->artisan('guard:sync-roles', ['--dry-run' => true])
        ->expectsOutputToContain('admin → sales:admin')
        ->expectsOutputToContain('(dry-run)')
        ->assertSuccessful();

    expect($legacy->fresh()->name)->toBe('admin');

    $this->artisan('guard:sync-roles')->assertSuccessful();

    $fresh = $legacy->fresh();
    expect($fresh->id)->toBe($legacy->id)
        ->and($fresh->name)->toBe('sales:admin')
        ->and($fresh->level)->toBe(7)
        ->and($user->fresh()->roles()->whereKey($legacy->id)->exists())->toBeTrue();
});

it('fails before write when a DB-only row holds the canonical name', function (): void {
    registerIdentityPanels();
    Role::query()->create(['name' => 'sales:admin', 'level' => 1]);

    $this->artisan('guard:sync-roles')
        ->expectsOutputToContain('Will not adopt a DB-only or foreign row')
        ->assertFailed();

    expect(Role::query()->where('class_name', SalesAdminRole::class)->exists())->toBeFalse();
});

it('fails before write when the same class is defined twice', function (): void {
    AzGuard::registerPanel(
        Panel::make()->id('one')->roleClasses([SalesAdminRole::class]),
    );
    AzGuard::registerPanel(
        Panel::make()->id('two')->roleClasses([SalesAdminRole::class]),
    );

    $this->artisan('guard:sync-roles')
        ->expectsOutputToContain('Duplicate code-role definition')
        ->assertFailed();
});

it('keeps built-in super-admin on the reserved name', function (): void {
    AzGuard::registerPanel(
        Panel::make()->id('ops')->roleClasses([SuperAdminRole::class]),
    );

    $this->artisan('guard:sync-roles')->assertSuccessful();

    $role = Role::query()->where('class_name', SuperAdminRole::class)->first();

    expect($role)->not->toBeNull()
        ->and($role->name)->toBe('super-admin');
});

it('uses the configured role model subclass', function (): void {
    config(['az-guard.models.role' => CustomRole::class]);
    CustomRole::$created = false;

    $this->artisan('guard:sync-roles')->assertSuccessful();

    expect(CustomRole::$created)->toBeTrue();
});
