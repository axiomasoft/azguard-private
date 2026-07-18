<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\ResourceGate;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

it('registers the discovered resource keys in the admin catalog', function (): void {
    $catalog = app(PermissionCatalog::class);

    expect($catalog->has('admin', 'admin.project.view_any'))->toBeTrue()
        ->and($catalog->has('admin', 'admin.project.create'))->toBeTrue()
        ->and($catalog->has('admin', 'admin.project.delete'))->toBeFalse();
});

it('grants resource access via a database role permission — no resource code', function (): void {
    $user = User::factory()->create();

    $role = Role::create(['name' => 'project-viewer', 'level' => 1]);
    RolePermission::create([
        'role_id' => $role->getKey(),
        'permission_key' => 'admin.project.view_any',
        'panel_id' => 'admin',
    ]);

    $user->assignRole('project-viewer');
    $user->load('roles');

    expect($user->hasPermission('admin.project.view_any', 'admin'))->toBeTrue();

    $gate = app(ResourceGate::class);

    expect($gate->check($user, 'viewAny', [Project::class]))->toBeTrue()
        ->and($gate->check($user, 'create', [Project::class]))->toBeNull();
});

it('defers (does not deny) resource access when the user has no permission (union-only, C-08)', function (): void {
    $user = User::factory()->create();
    $gate = app(ResourceGate::class);

    // NOT false: a Gate::before hook returning false would short-circuit the
    // entire gate, denying even abilities a later policy could still grant.
    expect($gate->check($user, 'viewAny', [Project::class]))->toBeNull();
});

it('a later Gate::before can still grant after ResourceGate defers (union-only, C-08)', function (): void {
    $user = User::factory()->create(); // no AzGuard permission for this ability

    // Registered AFTER AzGuardFilamentServiceProvider's own Gate::before (boot
    // already ran) — Laravel evaluates before-callbacks in registration order,
    // so this only runs (and can still grant) because ResourceGate returned
    // null rather than a short-circuiting false.
    Gate::before(fn ($u, string $ability): ?bool => $ability === 'viewAny' ? true : null);

    expect(Gate::forUser($user)->allows('viewAny', Project::class))->toBeTrue();
});

it('defers for models that are not managed resources', function (): void {
    $user = User::factory()->create();
    $gate = app(ResourceGate::class);

    expect($gate->check($user, 'viewAny', [User::class]))->toBeNull();
});
