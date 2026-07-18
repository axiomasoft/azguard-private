<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\User;

/**
 * H1 / F22 flip: wildcard-pattern grants ('test.post.*') are honoured against
 * the catalog by default, with the hierarchical grammar ('*' = one segment,
 * '**' = recursive). The deprecated features.wildcard_permission flag restores
 * the legacy 0.2 dot-crossing grammar for one cycle.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    $role = Role::create(['name' => 'post-editor', 'level' => 0]);
    $role->dbPermissions()->create(['permission_key' => 'test.post.*', 'panel_id' => 'test']);

    $this->user->assignRole('post-editor');
});

it('honours a prefix.* grant by default with the hierarchical grammar', function () {
    expect($this->user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($this->user->hasPermission('test.post.delete', 'test'))->toBeTrue()
        // A key outside the pattern's namespace is not granted.
        ->and($this->user->hasPermission('test.other.view', 'test'))->toBeFalse();
});

it('does not let a single * cross dot boundaries by default', function () {
    // 'test.*' covers only two-segment keys; the catalog holds three-segment
    // keys ('test.post.view', …) so the pattern covers nothing and is dropped.
    $user = User::factory()->create();
    $role = Role::create(['name' => 'star-root', 'level' => 0]);
    $role->dbPermissions()->create(['permission_key' => 'test.*', 'panel_id' => 'test']);
    $user->assignRole('star-root');

    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse();
});

it('recurses across segments with ** by default', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'double-star-root', 'level' => 0]);
    $role->dbPermissions()->create(['permission_key' => 'test.**', 'panel_id' => 'test']);
    $user->assignRole('double-star-root');

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.delete', 'test'))->toBeTrue();
});

it('drops a stale pattern that covers no catalog key', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'stale-pattern', 'level' => 0]);
    $role->dbPermissions()->create(['permission_key' => 'test.nonsense.*', 'panel_id' => 'test']);
    $user->assignRole('stale-pattern');

    expect($user->hasPermission('test.nonsense.anything', 'test'))->toBeFalse();
});

it('restores the legacy dot-crossing grammar via the deprecated wildcard_permission flag', function () {
    config()->set('az-guard.features.wildcard_permission', true);

    // Legacy '*' expands to '.*' and crosses dots — 'test.*' covers 'test.post.view'.
    $user = User::factory()->create();
    $role = Role::create(['name' => 'legacy-star-root', 'level' => 0]);
    $role->dbPermissions()->create(['permission_key' => 'test.*', 'panel_id' => 'test']);
    $user->assignRole('legacy-star-root');

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});
