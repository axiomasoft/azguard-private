<?php

declare(strict_types=1);

namespace AzGuard\Context;

/**
 * Singleton: holds the active AuthorizationContext for the current request.
 *
 * Registered as a singleton in AzGuardContextServiceProvider.
 * Set by the SetAuthorizationContext middleware (or manually).
 *
 * Example:
 *   app(AuthorizationContextManager::class)->set(
 *       new AuthorizationContext('app', 'workspace', $workspaceId)
 *   );
 *
 *   $ctx = app(AuthorizationContextManager::class)->current('app');
 */
final class AuthorizationContextManager
{
    /** @var array<string, AuthorizationContext> panelId => context */
    private array $contexts = [];

    /**
     * Set the context for a specific panel.
     */
    public function set(AuthorizationContext $context): void
    {
        $this->contexts[$context->panelId] = $context;
    }

    /**
     * Get the active context for a panel.
     * Returns null if no context is set.
     */
    public function current(string $panelId): ?AuthorizationContext
    {
        return $this->contexts[$panelId] ?? null;
    }

    /**
     * Check whether a context is set for the panel.
     */
    public function has(string $panelId): bool
    {
        return isset($this->contexts[$panelId]);
    }

    /**
     * Clear the context for a panel (e.g. in a test tearDown).
     */
    public function clear(string $panelId): void
    {
        unset($this->contexts[$panelId]);
    }

    /**
     * Clear all contexts.
     */
    public function clearAll(): void
    {
        $this->contexts = [];
    }
}
