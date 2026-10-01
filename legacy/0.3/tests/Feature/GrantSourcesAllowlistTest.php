<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Registry\Sources\DirectGrantSource;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\DB;

/**
 * B-10: `az-guard.grant_sources` restricts which GrantSources feed the
 * resolver (AzGuardServiceProvider::register(), EffectivePermissionResolver
 * scoped factory). Final ordering is always GrantPriority DESC regardless of
 * the array order given here — the allowlist can only restrict, not reorder.
 */
it('restricts resolution to the allowlisted grant sources', function () {
    config(['az-guard.features.direct_grants' => true]);

    $user = UserWithDirectGrants::factory()->create();

    $role = Role::create(['name' => 'editor', 'panel_id' => 'app']);
    DB::table(config('az-guard.table_names.role_permissions'))->insert([
        'role_id' => $role->getKey(),
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user->assignRole('editor');

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    // Baseline: with no allowlist, both sources contribute.
    expect($user->hasPermission('app.posts.edit', 'app'))->toBeTrue()
        ->and($user->hasPermission('app.reports.view', 'app'))->toBeTrue();

    config(['az-guard.grant_sources' => [DirectGrantSource::class]]);
    app()->forgetScopedInstances();
    $user->flushPermissions('app');

    expect($user->hasPermission('app.posts.edit', 'app'))->toBeFalse()
        ->and($user->hasPermission('app.reports.view', 'app'))->toBeTrue();
});
