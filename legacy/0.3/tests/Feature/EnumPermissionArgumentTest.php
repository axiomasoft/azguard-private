<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\UserWithDirectGrants;

/**
 * B14: hasPermission()/grant()/hasGrant() accept a permission enum, which is
 * scoped to the panel exactly like a fully-qualified key string.
 */
it('accepts a permission enum and scopes it to the panel', function () {
    $user = UserWithDirectGrants::factory()->create();

    AzGuard::forUser($user)->on('test')->grant(TestPermission::PostView);

    // Stored as the panel-scoped key, identical to the string form.
    $this->assertDatabaseHas('az_direct_grants', [
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ]);

    expect($user->hasGrant(TestPermission::PostView, 'test'))->toBeTrue()
        ->and($user->hasGrant('test.post.view', 'test'))->toBeTrue()
        ->and($user->hasPermission(TestPermission::PostView, 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    expect(AzGuard::forUser($user)->on('test')->revoke(TestPermission::PostView))->toBe(1)
        ->and($user->hasGrant('test.post.view', 'test'))->toBeFalse();
});
