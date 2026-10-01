<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\ContextGuard;
use AzGuard\Contracts\ContextGuard as ContextGuardContract;
use AzGuard\Contracts\PermissionLayer;

/**
 * Context-package half of the Octane state-leak guard (B1): the authorization
 * context and everything that captures it must be `scoped`, or one tenant's
 * context would leak into the next request on a reused worker.
 */
describe('Context Octane scoping', function () {

    it('rebuilds the context chain after a scoped flush', function () {
        $managerBefore = app(AuthorizationContextManager::class);
        $layerBefore = app(PermissionLayer::class);
        $guardBefore = app(ContextGuardContract::class);

        // Same request: scoped === shared instance.
        expect(app(AuthorizationContextManager::class))->toBe($managerBefore)
            ->and($guardBefore)->toBeInstanceOf(ContextGuard::class);

        app()->forgetScopedInstances();

        expect(app(AuthorizationContextManager::class))->not->toBe($managerBefore)
            ->and(app(PermissionLayer::class))->not->toBe($layerBefore)
            ->and(app(ContextGuardContract::class))->not->toBe($guardBefore);
    });
});
