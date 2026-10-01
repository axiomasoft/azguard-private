<?php

declare(strict_types=1);

use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\UserWithDirectGrants;

/**
 * F1: guard:grants (GrantsListCommand) must target grantable_type/grantable_id
 * — the columns created by the direct_grants migration — never model_type/model_id.
 *
 * The revoke round-trip exercises the registered, production-reachable
 * guard:revoke-grant (RevokeGrantCommand), which revokes through GrantBuilder.
 * The old raw-column RevokeCommand (guard:revoke) was an unregistered dead
 * duplicate and has been removed.
 */
it('guard:grants lists a direct grant using grantable columns', function () {
    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.documents.export',
        'expires_at' => null,
    ]);

    $this->artisan('guard:grants', [
        '--model' => UserWithDirectGrants::class,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('app.documents.export');
});

it('guard:grants filters by --user via grantable_id', function () {
    $target = UserWithDirectGrants::factory()->create();
    $other = UserWithDirectGrants::factory()->create();

    $target->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.target.only',
        'expires_at' => null,
    ]);
    $other->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.other.only',
        'expires_at' => null,
    ]);

    $this->artisan('guard:grants', [
        '--model' => UserWithDirectGrants::class,
        '--user' => (string) $target->getKey(),
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('app.target.only')
        ->doesntExpectOutputToContain('app.other.only');
});

it('guard:grants warns when no grants exist', function () {
    $this->artisan('guard:grants', [
        '--model' => UserWithDirectGrants::class,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('No grants found.');
});

it('round-trips: grant -> guard:grants shows it -> guard:revoke-grant removes -> guard:grants no longer shows it', function () {
    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.documents.export',
        'expires_at' => null,
    ]);

    // Grant is stored on the grantable columns.
    expect(DirectGrant::query()
        ->where('grantable_type', UserWithDirectGrants::class)
        ->where('grantable_id', $user->getKey())
        ->where('permission_key', 'app.documents.export')
        ->exists())->toBeTrue();

    // guard:grants lists it.
    $this->artisan('guard:grants', [
        '--model' => UserWithDirectGrants::class,
        '--user' => (string) $user->getKey(),
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('app.documents.export');

    // guard:revoke-grant removes it (production-reachable command).
    $this->artisan('guard:revoke-grant', [
        'user-id' => (string) $user->getKey(),
        'permission' => 'app.documents.export',
        'panel' => 'app',
        '--model' => UserWithDirectGrants::class,
        '--force' => true,
    ])->assertSuccessful();

    // Row is gone.
    expect(DirectGrant::query()
        ->where('grantable_type', UserWithDirectGrants::class)
        ->where('grantable_id', $user->getKey())
        ->where('permission_key', 'app.documents.export')
        ->exists())->toBeFalse();

    // guard:grants no longer lists it.
    $this->artisan('guard:grants', [
        '--model' => UserWithDirectGrants::class,
        '--user' => (string) $user->getKey(),
    ])
        ->assertSuccessful()
        ->doesntExpectOutputToContain('app.documents.export');
});

it('guard:revoke-grant --all clears the panel while leaving other panels intact', function () {
    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.a', 'expires_at' => null]);
    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.a2', 'expires_at' => null]);
    $user->directGrants()->create(['panel_id' => 'admin', 'permission_key' => 'admin.b', 'expires_at' => null]);

    $this->artisan('guard:revoke-grant', [
        'user-id' => (string) $user->getKey(),
        'permission' => 'ignored-with-all',
        'panel' => 'app',
        '--all' => true,
        '--model' => UserWithDirectGrants::class,
        '--force' => true,
    ])->assertSuccessful();

    // Every app-panel grant gone; admin-panel grant survives (per-panel scoping).
    expect(DirectGrant::query()
        ->where('grantable_id', $user->getKey())
        ->where('panel_id', 'app')
        ->count())->toBe(0);
    expect(DirectGrant::query()
        ->where('grantable_id', $user->getKey())
        ->where('panel_id', 'admin')
        ->count())->toBe(1);
});

it('guard:revoke-grant leaves other users grants intact (scoped by grantable_id)', function () {
    $user = UserWithDirectGrants::factory()->create();
    $other = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.mine', 'expires_at' => null]);
    $other->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.theirs', 'expires_at' => null]);

    $this->artisan('guard:revoke-grant', [
        'user-id' => (string) $user->getKey(),
        'permission' => 'ignored-with-all',
        'panel' => 'app',
        '--all' => true,
        '--model' => UserWithDirectGrants::class,
        '--force' => true,
    ])->assertSuccessful();

    expect(DirectGrant::query()
        ->where('grantable_id', $other->getKey())
        ->where('permission_key', 'app.theirs')
        ->exists())->toBeTrue();
});
