<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Contracts\ContextGuard as ContextGuardContract;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\SubjectIdentity;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Support\IdlePermissionStateRevision;
use Illuminate\Support\Facades\DB;

/**
 * F30-fix: ContextGuard::checkInContext performs a TRANSIENT within-request
 * context switch. It must invalidate ONLY the in-process request cache
 * (forgetRequestCache) — never the durable per-user+panel epoch (forgetForUser).
 *
 * Regression guarded here: on a persistent store, bumping the epoch on every
 * check would bust the entire cross-request cache for that user+panel and grow
 * the epoch unbounded (permanent cache-miss + full grant-source recompute per
 * check), violating F30's own "cache hit within the same epoch" AC.
 */
beforeEach(function (): void {
    // A named array-backed store persists across fresh PermissionCache instances,
    // standing in for a real cross-request store (redis/file).
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');

    $this->manager = app(AuthorizationContextManager::class);
    $this->manager->clearAll();

    $this->guard = app(ContextGuardContract::class);
});

it('does NOT advance the per-user epoch across repeated context checks (persistent store)', function (): void {
    $user = User::factory()->create();

    // Panel 'app' is not registered in the test catalog, so the resolved set is
    // returned unfiltered — matching the sibling ContextGuardTest.
    $subject = SubjectIdentity::fromAuthenticatable($user);
    $epochBefore = (new PermissionCache)->keyFor($subject, 'app');

    for ($i = 0; $i < 5; $i++) {
        $this->guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app');
    }

    $epochAfter = (new PermissionCache)->keyFor($subject, 'app');

    // The epoch (embedded in the v2 permission key) must be identical — no gratuitous
    // durable invalidation from a transient context switch.
    expect($epochAfter)->toBe($epochBefore)
        ->and($epochAfter)->toStartWith('azg:v2:perm:');
});

it('leaves a cross-request cached set intact across context checks', function (): void {
    $user = User::factory()->create();

    $subject = SubjectIdentity::fromAuthenticatable($user);

    // Seed the persistent store as if a previous request had already resolved &
    // cached this user's set for panel 'app' under the base (no-context) key.
    $key = (new PermissionCache)->keyFor($subject, 'app');
    cache()->store('azguard_test')->forever($key, ['app.posts.view']);

    // A transient context check must not bump the epoch, so the key stays valid.
    $this->guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app');

    $keyAfter = (new PermissionCache)->keyFor($subject, 'app');

    expect($keyAfter)->toBe($key)
        ->and(cache()->store('azguard_test')->get($key))->toBe(['app.posts.view']);
});

it('a real grant/role change STILL bumps the epoch (durable invalidation preserved)', function (): void {
    $user = User::factory()->create();

    $subject = SubjectIdentity::fromAuthenticatable($user);

    $cache = new PermissionCache(permissionState: new IdlePermissionStateRevision);
    $before = $cache->keyFor($subject, 'app');

    // Simulate the durable path used by DirectGrant/role events/HasPermissions.
    $cache->forgetForUser($subject, 'app');

    $after = $cache->keyFor($subject, 'app');

    expect($after)->not->toBe($before);
});

it('returns the correct decision under a switched context', function (): void {
    $user = User::factory()->create();

    DB::table('az_guard_context_roles')->insert([
        'model_type' => User::class,
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeTrue()
        // A different context id has no grant → denied.
        ->and($this->guard->checkInContext($user, 'workspace', 99, 'app.posts.edit', 'app'))->toBeFalse();
});

it('restores the previous context in finally', function (): void {
    $user = User::factory()->create();
    $this->manager->set(new AuthorizationContext('app', 'project', 7));

    $this->guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app');

    $restored = $this->manager->current('app');

    expect($restored)->not->toBeNull()
        ->and($restored->contextType)->toBe('project')
        ->and($restored->contextId)->toBe(7);
});

it('clears the context in finally when none existed before', function (): void {
    $user = User::factory()->create();

    expect($this->manager->has('app'))->toBeFalse();

    $this->guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app');

    expect($this->manager->has('app'))->toBeFalse();
});
