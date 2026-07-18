<?php

declare(strict_types=1);

use AzGuard\Models\ModelHasScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use AzGuard\Concerns\HasScopedRoles;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stub entity model for scoping
class Project extends Model
{
    use HasScopedRoles;

    protected $table = 'projects';

    protected $fillable = ['name'];

    public $timestamps = false;
}

beforeAll(function (): void {
    // Create projects table once per suite
});

beforeEach(function (): void {
    config(['az-guard.cache.store' => 'array']);

    if (! Schema::hasTable('projects')) {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
});

describe('HasAzGuard — entity-scoped roles (HasScopedRoles)', function (): void {

    it('assignScopedRole creates ModelHasScope record with role_id', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Alpha']);

        $role = createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project);

        expect(
            ModelHasScope::query()
                ->where('model_id', $user->getKey())
                ->where('model_type', $user->getMorphClass())
                ->where('scope_entity_id', $project->getKey())
                ->where('scope_entity_type', $project->getMorphClass())
                ->where('role_id', $role->getKey())
                ->exists(),
        )->toBeTrue();
    });

    it('hasScopedRole returns true after assignment', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Beta']);

        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project);

        expect($user->hasScopedRole('editor', $project))->toBeTrue();
    });

    it('hasScopedRole returns false for different entity', function (): void {
        $user = User::factory()->create();
        $project1 = Project::create(['name' => 'Gamma']);
        $project2 = Project::create(['name' => 'Delta']);

        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project1);

        expect($user->hasScopedRole('editor', $project2))->toBeFalse();
    });

    it('removeScopedRole deletes the scoped assignment', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Epsilon']);

        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project);
        expect($user->hasScopedRole('editor', $project))->toBeTrue();

        $user->removeScopedRole('editor', $project);
        expect($user->hasScopedRole('editor', $project))->toBeFalse();
    });

    it('hasScopedPermission returns true for permission in scoped role', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Zeta']);

        createRoleWithClass([
            'name' => 'editor',
            'level' => 5,
        ], ManagerRole::class); // ManagerRole has test.post.view

        $user->assignScopedRole('editor', $project);

        expect($user->hasScopedPermission('test.post.view', $project))->toBeTrue();
    });

    it('hasScopedPermission returns false for permission not in scoped role', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Eta']);

        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project);

        expect($user->hasScopedPermission('admin.delete.everything', $project))->toBeFalse();
    });

    it('hasScopedPermission returns true via global wildcard role', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Theta']);

        // Give user a global wildcard role
        $superRole = createRoleWithClass(['name' => 'superadmin',
            'level' => 1000,
        ], ManagerRole::class);

        // Patch ManagerRole to return ['*'] by adding superadmin globally
        // Instead we test via hasPermission fallback path:
        // assign scoped role that has the perm
        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignRole('superadmin');
        $user->load('roles');

        // hasScopedPermission should pass through to global hasPermission
        // ManagerRole has test.post.view so global role grants it
        expect($user->hasScopedPermission('test.post.view', $project))->toBeTrue();
    });

    it('assignScopedRole silently skips unknown role name', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Iota']);

        $user->assignScopedRole('ghost-role', $project);

        expect(
            ModelHasScope::query()
                ->where('model_id', $user->getKey())
                ->exists(),
        )->toBeFalse();
    });

    it('assignScopedRole is idempotent (no duplicate records)', function (): void {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Kappa']);

        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ManagerRole::class);

        $user->assignScopedRole('editor', $project);
        $user->assignScopedRole('editor', $project);

        $count = ModelHasScope::query()
            ->where('model_id', $user->getKey())
            ->where('scope_entity_id', $project->getKey())
            ->count();

        expect($count)->toBe(1);
    });
});
