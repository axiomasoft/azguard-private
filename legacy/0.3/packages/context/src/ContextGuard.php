<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Contracts\ContextGuard as ContextGuardContract;
use AzGuard\Contracts\PermissionResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Context-package implementation of {@see ContextGuardContract}.
 *
 * Temporarily sets the requested context, resolves the user's effective
 * permissions through the normal grant-source chain, then always restores
 * the previous context — even if resolution throws.
 */
final readonly class ContextGuard implements ContextGuardContract
{
    public function __construct(
        private AuthorizationContextManager $manager,
        private PermissionResolverInterface $resolver,
    ) {}

    #[Override]
    public function checkInContext(
        Authenticatable $user,
        string $contextType,
        int|string $contextId,
        string $permission,
        string $panelId,
    ): bool {
        $context = new AuthorizationContext($panelId, $contextType, $contextId);
        $previous = $this->manager->current($panelId);

        $this->manager->set($context);

        // In-process-only: this is a transient, within-request context switch,
        // not a real grant/role change — must not bump the durable per-user
        // epoch (that would bust the entire cross-request cache for this
        // user+panel on a persistent store on every single context check).
        // ContextPermissionLayer::cacheDiscriminator() already keys the
        // request-local slot by context, but the resolved set is cached under
        // the discriminator of whichever context was active on first
        // resolution — dropping it here guarantees this check recomputes
        // against the context just set, not a stale in-memory entry.
        $this->resolver->forgetRequestCache($user, $panelId);

        try {
            return $this->resolver->forUser($user, $panelId)->grants($permission);
        } finally {
            $previous instanceof AuthorizationContext
                ? $this->manager->set($previous)
                : $this->manager->clear($panelId);

            $this->resolver->forgetRequestCache($user, $panelId);
        }
    }
}
