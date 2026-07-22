<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Runtime\RequestState;
use AzGuard\Runtime\ScopedRoleCache;

/**
 * Guards against the Octane state-leak (B1): per-request services must be
 * bound `scoped`, so a reused worker does not bleed one request's resolved
 * permissions into the next. forgetScopedInstances() is exactly what Laravel
 * Octane calls between requests.
 */
describe('Octane scoping', function () {

    it('rebuilds per-request services after a scoped flush', function () {
        $cacheBefore = app(PermissionCache::class);
        $resolverBefore = app(EffectivePermissionResolver::class);
        $scopedRolesBefore = app(ScopedRoleCache::class);

        // Same request: scoped === shared instance.
        expect(app(PermissionCache::class))->toBe($cacheBefore)
            ->and(app(EffectivePermissionResolver::class))->toBe($resolverBefore);

        // Next Octane request on the same worker.
        app()->forgetScopedInstances();

        expect(app(PermissionCache::class))->not->toBe($cacheBefore)
            ->and(app(EffectivePermissionResolver::class))->not->toBe($resolverBefore)
            ->and(app(ScopedRoleCache::class))->not->toBe($scopedRolesBefore);
    });

    it('keeps the AzGuardManager singleton but resets its current panel between requests', function () {
        $manager = app(AzGuardManager::class);
        $panel = $manager->panel('app');
        $manager->setCurrentPanel($panel);

        expect($manager->currentPanel())->toBe($panel);

        // Simulate Octane RequestReceived firing for the next request.
        app('events')->dispatch('Laravel\Octane\Events\RequestReceived');

        expect(app(AzGuardManager::class))->toBe($manager)
            ->and($manager->currentPanel())->toBeNull();
    });

    it('does not leak RequestState scope_class or panel state across a scoped flush', function () {
        $calls = 0;
        $firstRequest = app(RequestState::class);

        $firstRequest->once('scope_class:App\\Models\\Workspace', function () use (&$calls): void {
            $calls++;
        });
        $firstRequest->once('panel:admin', function () use (&$calls): void {
            $calls++;
        });

        expect($calls)->toBe(2);

        app()->forgetScopedInstances();

        $secondRequest = app(RequestState::class);
        $secondRequest->once('scope_class:App\\Models\\Workspace', function () use (&$calls): void {
            $calls++;
        });
        $secondRequest->once('panel:admin', function () use (&$calls): void {
            $calls++;
        });

        expect($secondRequest)->not->toBe($firstRequest)
            ->and($calls)->toBe(4);
    });
});
