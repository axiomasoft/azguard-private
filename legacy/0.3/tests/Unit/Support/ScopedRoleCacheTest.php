<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\SubjectIdentity;
use AzGuard\Runtime\ScopedRoleCache;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Support\IdlePermissionStateRevision;

it('remembers a value and resolves it only once', function (): void {
    $cache = new ScopedRoleCache(new IdlePermissionStateRevision);
    $calls = 0;

    $resolve = function () use (&$calls): string {
        $calls++;

        return 'value';
    };

    expect($cache->remember('key', $resolve))->toBe('value')
        ->and($cache->remember('key', $resolve))->toBe('value')
        ->and($calls)->toBe(1);
});

it('flush() drops cached values', function (): void {
    $cache = new ScopedRoleCache(new IdlePermissionStateRevision);
    $cache->remember('key', fn (): int => 1);
    $cache->flush();

    expect($cache->remember('key', fn (): int => 2))->toBe(2);
});

it('is bound as a scoped instance and reset on a new request scope', function (): void {
    $first = app(ScopedRoleCache::class);

    expect($first)->toBeInstanceOf(ScopedRoleCache::class)
        ->and(app(ScopedRoleCache::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(ScopedRoleCache::class))->not->toBe($first);
});

it('does not share scoped-role cache entries between morph types with the same id', function (): void {
    $cache = new ScopedRoleCache(new IdlePermissionStateRevision);

    $user = SubjectIdentity::fromPersisted(User::class, 1);
    $admin = SubjectIdentity::fromPersisted('AzGuard\\Tests\\Stubs\\AdminActor', 1);
    $entity = User::class;

    $userCalls = 0;
    $adminCalls = 0;

    $cache->remember($user->scopedRolesRequestKey($entity), function () use (&$userCalls): string {
        $userCalls++;

        return 'user-scopes';
    });

    $cache->remember($admin->scopedRolesRequestKey($entity), function () use (&$adminCalls): string {
        $adminCalls++;

        return 'admin-scopes';
    });

    expect($userCalls)->toBe(1)
        ->and($adminCalls)->toBe(1)
        ->and($cache->remember($user->scopedRolesRequestKey($entity), fn (): string => 'again'))->toBe('user-scopes');
});

it('bypasses reads and writes while the authorization connection is in a transaction', function (): void {
    $state = new IdlePermissionStateRevision;
    $cache = new ScopedRoleCache($state);
    $calls = 0;

    $cache->remember('key', function () use (&$calls): string {
        $calls++;

        return 'warm';
    });

    $state->inTransaction = true;

    expect($cache->remember('key', function () use (&$calls): string {
        $calls++;

        return 'inside';
    }))->toBe('inside');

    $state->inTransaction = false;

    expect($calls)->toBe(2)
        ->and($cache->remember('key', fn (): string => 'after'))->toBe('warm');
});

it('does not reuse an entry after the permission-state revision advances', function (): void {
    $state = new IdlePermissionStateRevision(7);
    $cache = new ScopedRoleCache($state);
    $calls = 0;

    $cache->remember('key', function () use (&$calls): string {
        $calls++;

        return 'r7';
    });

    $state->revision = 8;

    expect($cache->remember('key', function () use (&$calls): string {
        $calls++;

        return 'r8';
    }))->toBe('r8')
        ->and($calls)->toBe(2);
});
