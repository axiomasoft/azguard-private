<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Contracts\PermissionContext;

/**
 * Value object: describes an authorization context.
 *
 * Example: a user working with workspace #42 of the "app" panel.
 *
 *   $ctx = new AuthorizationContext(
 *       panelId:     'app',
 *       contextType: 'workspace',
 *       contextId:   42,
 *   );
 *
 * Immutable — changes create a new instance (withPanel / withContext).
 */
final readonly class AuthorizationContext implements PermissionContext
{
    public function __construct(
        public string $panelId,
        public string $contextType,
        public int|string $contextId,
    ) {}

    public function contextType(): string
    {
        return $this->contextType;
    }

    public function contextId(): int|string
    {
        return $this->contextId;
    }

    /**
     * Return a new context with a different panelId.
     */
    public function withPanel(string $panelId): self
    {
        return new self($panelId, $this->contextType, $this->contextId);
    }

    /**
     * Return a new context with a different entity.
     */
    public function withContext(string $contextType, int|string $contextId): self
    {
        return new self($this->panelId, $contextType, $contextId);
    }

    /**
     * String key for cache / logs.
     */
    public function cacheKey(): string
    {
        return "{$this->panelId}:{$this->contextType}:{$this->contextId}";
    }

    public function equals(self $other): bool
    {
        return $this->panelId === $other->panelId
            && $this->contextType === $other->contextType
            && $this->contextId === $other->contextId;
    }
}
