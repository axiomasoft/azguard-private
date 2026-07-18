<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Models\ModelHasScope;
use AzGuard\Support\Panel;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * C-10 tail (P1.4 review) — assignScopedRole() persists scope_entity_type via
 * getMorphClass(), which is the ALIAS under an enforced morph map. The
 * bootHasScopedRoles() read side must resolve the entity type the same way,
 * or the scope rows are never found and the isolation filter silently does
 * not apply (fail-open, with no stale-class warning to surface it).
 */
afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    AzGuard::setCurrentPanel(panel: null);
});

it('applies the scoped-role query filter when an enforced morph map aliases the entity', function (): void {
    Relation::enforceMorphMap([
        'test-user' => User::class,
        'test-project' => Project::class,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $scoped = Project::factory()->create();
    $other = Project::factory()->create();

    $role = createRoleWithClass(['name' => 'scoped-filter-morph-alias',
        'level' => 1,
    ], ScopedFilterRole::class);

    $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

    // The row is persisted under the alias, not the FQCN — the exact shape
    // that used to be invisible to the read path.
    expect(ModelHasScope::query()->value('scope_entity_type'))->toBe('test-project');

    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'panel-a')->label(label: 'A'));

    $visibleIds = Project::all()->pluck('id')->all();

    expect($visibleIds)->toBe([$scoped->id])
        ->and($visibleIds)->not->toContain($other->id);
});
