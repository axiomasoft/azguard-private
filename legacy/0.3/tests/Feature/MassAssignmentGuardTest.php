<?php

declare(strict_types=1);

use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\SuperAdminRole;
use AzGuard\Tests\Stubs\User;

/**
 * C-11 regression proof (P1.4 review) — class_name (Role) and scope_class
 * (ModelHasScope) are privilege-bearing columns: class_name is instantiated
 * by getRoleLogic() and hands out permissions (up to '*'), scope_class is
 * container-resolved inside the query scope. Neither may ever be settable
 * from mass-assigned input (Role::create($request->all()) is the canonical
 * consumer shape of the escalation vector).
 */
it('ignores class_name in Role mass-assignment (create/fill/update)', function (): void {
    $role = Role::create([
        'name' => 'sneaky',
        'level' => 1,
        'class_name' => SuperAdminRole::class,
    ]);

    expect($role->refresh()->class_name)->toBeNull();

    $role->update(['class_name' => SuperAdminRole::class]);

    expect($role->refresh()->class_name)->toBeNull()
        ->and($role->getRoleLogic())->toBeNull();
});

it('ignores scope_class in ModelHasScope mass-assignment, including firstOrCreate values', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'plain', 'level' => 1]);

    $attributes = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'role_id' => $role->getKey(),
        'panel_id' => null,
    ];

    $scope = ModelHasScope::query()->create($attributes + ['scope_class' => SuperAdminRole::class]);

    expect($scope->refresh()->scope_class)->toBeNull();

    $scope->delete();

    // firstOrCreate()'s second array goes through the SAME fill()/fillable
    // check as create() — the P1.2 premise "second argument bypasses
    // fillable" was FALSE (see P1.2 Known Deviations); pin the real behavior.
    $viaValues = ModelHasScope::query()->firstOrCreate($attributes, ['scope_class' => SuperAdminRole::class]);

    expect($viaValues->refresh()->scope_class)->toBeNull();
});
