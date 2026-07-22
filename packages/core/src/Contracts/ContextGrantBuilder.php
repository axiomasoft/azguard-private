<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The context branch of the unified grant grammar.
 *
 * Returned by the core fluent root when it is extended into a context:
 *
 *   AzGuard::forUser($user)
 *       ->on('app')
 *       ->inContext('workspace', 42)
 *       ->until($expiry)
 *       ->grant('app.posts.edit');
 *
 * This contract lives in core, but is implemented by the optional
 * azguard/context package (same pattern as {@see ContextGuard}): core
 * depends only on the contract; when the context package is absent the
 * factory binding is not registered and `inContext()` fails loudly.
 *
 * Implementations are immutable: every scope setter returns a NEW instance.
 *
 * @api
 */
interface ContextGrantBuilder
{
    public function on(string|BackedEnum $panelId): static;

    public function inContext(string $contextType, int|string $contextId): static;

    /**
     * Set an absolute expiry timestamp. null = no expiry. Clears any ttl()
     * (last wins).
     */
    public function until(?DateTimeInterface $at): static;

    /**
     * Set TTL in seconds. null = no expiry. Clears any until() (last wins).
     */
    public function ttl(?int $seconds): static;

    /**
     * Grant a permission in the current context.
     */
    public function grant(string|UnitEnum $permission): Model;

    /**
     * Revoke a specific permission in the current context.
     *
     * @return int Number of deleted records (0 or 1).
     */
    public function revoke(string|UnitEnum $permission): int;

    /**
     * Revoke every permission the user holds in the current context+panel.
     *
     * @return int Number of deleted records.
     */
    public function revokeAll(): int;

    /**
     * Return all active (non-expired) grants for the user in the current
     * context+panel.
     *
     * @return Collection<int, covariant Model>
     */
    public function grants(): Collection;
}
