<?php

declare(strict_types=1);

use AzGuard\Concerns\HasScopedRoles;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Stub entity model carrying the HasScopedRoles global scope. Named distinctly
 * from Project in HasAzGuardScopedRolesTest to avoid a class redeclaration.
 */
class ScopedEntity extends Model
{
    use HasScopedRoles;

    protected $table = 'scoped_entities';

    protected $fillable = ['name'];

    public $timestamps = false;
}

beforeEach(function (): void {
    config(['az-guard.cache.store' => 'array']);

    if (! Schema::hasTable('scoped_entities')) {
        Schema::create('scoped_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
});

describe('F48 — scope_class nullable for logic-less scoped roles', function (): void {

    it('stores null in scope_class for a logic-less role (no anon-class sentinel)', function (): void {
        $user = User::factory()->create();
        $entity = ScopedEntity::create(['name' => 'Alpha']);

        // A logic-less role: class_name is null, so getRoleLogic() returns null.
        Role::create(['name' => 'observer',
            'level' => 1,
        ]);

        $user->assignScopedRole('observer', $entity);

        $scope = ModelHasScope::query()
            ->where('model_id', $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where('scope_entity_id', $entity->getKey())
            ->where('scope_entity_type', $entity->getMorphClass())
            ->first();

        expect($scope)->not->toBeNull()
            ->and($scope->scope_class)->toBeNull();
    });

    it('does not throw when resolving a null scope_class via the global scope', function (): void {
        $user = User::factory()->create();
        $entity = ScopedEntity::create(['name' => 'Beta']);

        Role::create(['name' => 'observer',
            'level' => 1,
        ]);

        $user->assignScopedRole('observer', $entity);

        // Authenticate so the HasScopedRoles global scope actually runs its body.
        Auth::login($user);

        // A read query on the scoped entity triggers the global scope, which must
        // treat a null scope_class as logic-less and NOT instantiate anything.
        $result = null;
        expect(function () use (&$result): void {
            $result = ScopedEntity::query()->get();
        })->not->toThrow(Throwable::class);

        expect($result)->not->toBeNull();
    });

    it('leaves logic-bearing scoped roles unaffected (stores the RoleInterface class)', function (): void {
        $user = User::factory()->create();
        $entity = ScopedEntity::create(['name' => 'Gamma']);

        // A logic-bearing role: class_name resolves to a RoleInterface.
        createRoleWithClass(['name' => 'editor',
            'level' => 5,
        ], ProjectEditorRole::class);

        $user->assignScopedRole('editor', $entity);

        $scope = ModelHasScope::query()
            ->where('model_id', $user->getKey())
            ->where('scope_entity_id', $entity->getKey())
            ->first();

        expect($scope)->not->toBeNull()
            ->and($scope->scope_class)->toBe(ProjectEditorRole::class);
    });

    it('keeps hasScopedRole/removeScopedRole working for a null-scope_class role', function (): void {
        $user = User::factory()->create();
        $entity = ScopedEntity::create(['name' => 'Delta']);

        Role::create(['name' => 'observer',
            'level' => 1,
        ]);

        $user->assignScopedRole('observer', $entity);
        expect($user->hasScopedRole('observer', $entity))->toBeTrue();

        $user->removeScopedRole('observer', $entity);
        expect($user->hasScopedRole('observer', $entity))->toBeFalse();
    });
});
