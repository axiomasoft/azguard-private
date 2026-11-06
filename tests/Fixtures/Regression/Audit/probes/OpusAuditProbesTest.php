<?php

declare(strict_types=1);

/**
 * Opus audit probes (2026-09-29). Each probe asserts the CURRENT behaviour of
 * 0.3.x at 253cbf4 — i.e. a passing probe demonstrates the defect described in
 * audits/2026-09-29-audit/opus/01-review.md. Not part of the product suite.
 *
 * DatabaseMigrations (not RefreshDatabase) on purpose: no wrapping transaction,
 * so the permission cache runs exactly as in production.
 */

use AzGuard\Context\AuthorizationContext;
use AzGuard\Contracts\ContextGuard;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Events\GrantGiven;
use AzGuard\Exceptions\InvalidRoleClassException;
use AzGuard\Facades\AzGuard;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use AzGuard\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    // DatabaseMigrations runs before TestCase::defineDatabaseMigrations(); load the stub tables here.
    $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
});

enum OpusProbeAdminPermission: string
{
    case UsersDelete = 'users.delete';
}

final class OpusProbeDenyAllResolver implements PermissionResolverInterface
{
    public function forUser(Authenticatable $user, string $panelId): PermissionSet
    {
        return PermissionSet::empty();
    }

    public function forgetForUser(Authenticatable $user, string $panelId): void {}

    public function forgetRequestCache(Authenticatable $user, string $panelId): void {}
}

function opusRegisterAdminPanel(): void
{
    AzGuard::registerPanel(Panel::make()->id('admin')->permissionEnums([OpusProbeAdminPermission::class]));

    $abstract = 'azguard.catalog_builder.admin.enum';
    app()->instance($abstract, new EnumPermissionCatalogBuilder(
        panelId: 'admin',
        enumClasses: [OpusProbeAdminPermission::class],
    ));
    app()->tag([$abstract], 'azguard.catalog_builders');
    app()->forgetInstance(PermissionCatalog::class);
    app()->forgetScopedInstances();
}

// ─── P01 — class-role wildcard crosses panels (N01) ─────────────────────────

it('P01a: a class role with * registered on panel "test" is superadmin on panel "admin"', function (): void {
    opusRegisterAdminPanel();
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'test:super'], SuperAdminRole::class);
    $user->assignRole($role);

    expect(app(PermissionCatalog::class)->has('admin', 'admin.users.delete'))->toBeTrue()
        ->and($user->hasPermission('admin.users.delete', 'admin'))->toBeTrue()
        ->and($user->isSuperAdmin('admin'))->toBeTrue();

    AzGuard::setCurrentPanel(AzGuard::panel('admin'));
    expect(Gate::forUser($user)->allows('admin.users.delete'))->toBeTrue();
});

it('P01b: the same * stored on a DB role stays on its own panel (asymmetry)', function (): void {
    opusRegisterAdminPanel();
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'db-super']);
    RolePermission::query()->create(['role_id' => $role->id, 'panel_id' => 'test', 'permission_key' => '*']);
    $user->assignRole($role);

    expect($user->isSuperAdmin('test'))->toBeTrue()
        ->and($user->hasPermission('admin.users.delete', 'admin'))->toBeFalse();
});

it('P01c: hasPermission() evaluates a foreign-panel key against the default panel set', function (): void {
    opusRegisterAdminPanel();
    config()->set('az-guard.default_panel', 'test');
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'db-super-test']);
    RolePermission::query()->create(['role_id' => $role->id, 'panel_id' => 'test', 'permission_key' => '*']);
    $user->assignRole($role);

    // Key names panel "admin", no explicit panel → evaluated against panel "test".
    expect($user->hasPermission('admin.users.delete'))->toBeTrue()
        ->and($user->hasPermission('admin.users.delete', 'admin'))->toBeFalse();
});

// ─── P02 — a role row pointing at a missing class breaks every check (N02/N12) ─

it('P02: a role class_name that no longer resolves makes every check of its holders throw', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'test:renamed'], ManagerRole::class);
    $user->assignRole($role);
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // What EditRole does with a free-text FQCN, or what a class rename leaves behind.
    $role->class_name = 'App\\Roles\\RenamedManagerRole';
    $role->save();
    app()->forgetScopedInstances();

    expect(fn () => $user->hasPermission('test.post.view', 'test'))->toThrow(InvalidRoleClassException::class)
        ->and(fn () => Gate::forUser($user)->allows('test.post.view'))->toThrow(InvalidRoleClassException::class);
});

// ─── P03 — Gate ignores the configured resolver (N03) ───────────────────────

it('P03: a restrictive az-guard.resolver is honoured by hasPermission() but not by the Gate', function (): void {
    $user = User::factory()->create();
    $user->assignRole(createRoleWithClass(['name' => 'test:manager'], ManagerRole::class));

    config()->set('az-guard.resolver', OpusProbeDenyAllResolver::class);
    app()->forgetScopedInstances();

    expect(app(PermissionResolverInterface::class))->toBeInstanceOf(OpusProbeDenyAllResolver::class)
        ->and($user->hasPermission('test.post.view', 'test'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('test.post.view'))->toBeTrue();
});

// ─── P04 — HasScopedRoles query "isolation" (N04) ───────────────────────────

it('P04a: without an authenticated user the scoped model query is unfiltered', function (): void {
    $user = User::factory()->create();
    [$a, $b, $c] = [Project::factory()->create(), Project::factory()->create(), Project::factory()->create()];
    $user->assignScopedRole(createRoleWithClass(['name' => 'test:filter'], ScopedFilterRole::class), $a, 'test');
    AzGuard::setCurrentPanel(AzGuard::panel('test'));

    // Queue job / console / public route: no Auth::user().
    expect(Project::query()->count())->toBe(3);
});

it('P04b: an authenticated user with no scoped rows sees every row', function (): void {
    $outsider = User::factory()->create();
    Project::factory()->count(3)->create();
    AzGuard::setCurrentPanel(AzGuard::panel('test'));
    $this->actingAs($outsider);

    expect(Project::query()->count())->toBe(3);
});

it('P04c: two scoped assignments compose with AND and hide both projects', function (): void {
    $user = User::factory()->create();
    [$a, $b] = [Project::factory()->create(), Project::factory()->create()];
    Project::factory()->create();
    $role = createRoleWithClass(['name' => 'test:filter'], ScopedFilterRole::class);
    $user->assignScopedRole($role, $a, 'test');
    $user->assignScopedRole($role, $b, 'test');
    AzGuard::setCurrentPanel(AzGuard::panel('test'));
    $this->actingAs($user);

    expect(Project::query()->count())->toBe(0);
});

// ─── P08 — hasScopedPermission ignores DB roles and patterns (N08) ──────────

it('P08: a DB role grants globally but not when assigned scoped; patterns are ignored when scoped', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $dbRole = Role::query()->create(['name' => 'db-viewer']);
    RolePermission::query()->create(['role_id' => $dbRole->id, 'panel_id' => 'test', 'permission_key' => 'test.post.view']);

    $user->assignScopedRole($dbRole, $project, 'test');
    expect($user->hasScopedPermission('test.post.view', $project, 'test'))->toBeFalse();

    $user->assignRole($dbRole);
    app()->forgetScopedInstances();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // Logic-less scope rows (scope_class NULL) make migration 000004 irreversible (N21) —
    // clean up so DatabaseMigrations can roll back.
    ModelHasScope::query()->delete();
});

// ─── P09 — panel resolution ignores the key prefix (N09) ────────────────────

it('P09: with two panels and no current panel the Gate abstains on a fully-qualified key', function (): void {
    opusRegisterAdminPanel();
    $user = UserWithDirectGrants::factory()->create();
    $user->grant('admin.users.delete', 'admin');
    app()->forgetScopedInstances();

    expect($user->hasPermission('admin.users.delete', 'admin'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('admin.users.delete'))->toBeFalse()
        ->and($user->hasPermission('admin.users.delete'))->toBeFalse(); // 'app' fallback
});

// ─── P10 — hot path issues one revision SELECT per check (N17) ──────────────

it('P10: ten checks of a warm request cache still issue ten revision SELECTs', function (): void {
    $user = User::factory()->create();
    $user->assignRole(createRoleWithClass(['name' => 'test:manager'], ManagerRole::class));
    app()->forgetScopedInstances();
    $user->hasPermission('test.post.view', 'test'); // warm

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    for ($i = 0; $i < 10; $i++) {
        $user->hasPermission('test.post.view', 'test');
    }

    $revisionReads = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'az_guard_permission_state'));
    expect(count($revisionReads))->toBe(10);
});

it('P10b: inside any transaction on the connection the cache is bypassed and sources re-query', function (): void {
    $user = User::factory()->create();
    $user->assignRole(createRoleWithClass(['name' => 'test:manager'], ManagerRole::class));
    app()->forgetScopedInstances();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    DB::transaction(function () use ($user): void {
        for ($i = 0; $i < 5; $i++) {
            $user->hasPermission('test.post.view', 'test');
        }
    });

    $roleReads = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'model_has_roles'));
    expect(count($roleReads))->toBeGreaterThanOrEqual(5);
});

// ─── P11 — event contract differs per entry point (N11) ─────────────────────

it('P11: trait grant() emits no event, a no-op re-grant emits one, and events fire inside the transaction', function (): void {
    $levels = [];
    Event::listen(GrantGiven::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $user = UserWithDirectGrants::factory()->create();
    $user->grant('test.post.view', 'test');
    expect($levels)->toBe([]);

    AzGuard::forUser($user)->on('test')->grant('test.post.view'); // row already exists, nothing changes
    expect($levels)->toHaveCount(1)
        ->and($levels[0])->toBeGreaterThan(0);
});

// ─── P13 — silent no-op writes (N14) ────────────────────────────────────────

it('P13: assignRole() with an unknown role and a grant of an unknown key both "succeed"', function (): void {
    $user = UserWithDirectGrants::factory()->create();
    $user->assignRole('edtor');
    expect($user->roles()->count())->toBe(0);

    $grant = AzGuard::forUser($user)->on('test')->grant('test.post.veiw');
    app()->forgetScopedInstances();
    expect($grant->exists)->toBeTrue()
        ->and($user->hasPermission('test.post.veiw', 'test'))->toBeFalse();
});

// ─── P14 — direct grant of * is superadmin (N13) ────────────────────────────

it('P14: a direct grant of * through the public fluent API makes the user superadmin', function (): void {
    $user = UserWithDirectGrants::factory()->create();
    AzGuard::forUser($user)->on('test')->grant('*');
    app()->forgetScopedInstances();

    expect($user->isSuperAdmin('test'))->toBeTrue();
});

// ─── P06b — a context argument is silently dropped without azguard-context (N06) ─

it('P06b: hasPermission(..., $context) falls back to the global answer while hasPermissionIn() denies', function (): void {
    $user = User::factory()->create();
    $user->assignRole(createRoleWithClass(['name' => 'test:manager'], ManagerRole::class));
    app()->forgetScopedInstances();

    $workspace = new AuthorizationContext('test', 'workspace', 42);

    expect(app()->bound(ContextGuard::class))->toBeFalse()
        ->and($user->hasPermission('test.post.view', 'test', $workspace))->toBeTrue()
        ->and($user->hasPermissionIn('workspace', 42, 'test.post.view', 'test'))->toBeFalse();
});
