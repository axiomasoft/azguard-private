<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Context\Events\ContextGrantGiven;
use AzGuard\Context\Events\ContextGrantRevoked;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Contracts\ContextGrantBuilder as ContextGrantBuilderContract;
use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Panels\PanelResolver;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Permissions\PermissionName;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Override;
use UnitEnum;

/**
 * The context branch of the unified grant grammar (az_guard_context_roles).
 *
 * Immutable counterpart to AzGuard\Grants\GrantBuilder: that builder writes
 * panel-wide direct grants, this one writes grants scoped to a
 * (contextType, contextId) pair — e.g. "workspace #42". Every scope setter
 * returns a NEW instance.
 *
 * Usage (via the unified fluent root):
 *   AzGuard::forUser($user)
 *       ->on('app')
 *       ->inContext('workspace', 42)
 *       ->until($expiry)
 *       ->grant('app.documents.export');
 */
final readonly class ContextGrantBuilder implements ContextGrantBuilderContract
{
    /**
     * @internal Direct construction is package wiring — obtain the builder
     *           through the unified root: AzGuard::forUser($user)->inContext(...).
     */
    public function __construct(
        private Authenticatable $user,
        private ?string $panelId = null,
        private ?string $contextType = null,
        private int|string|null $contextId = null,
        private ?int $ttlSeconds = null,
        private ?DateTimeInterface $expiresAt = null,
    ) {}

    // ─── Fluent setters (immutable-with) ─────────────────────────────────────

    #[Override]
    public function on(string|BackedEnum $panelId): static
    {
        return new self(
            user: $this->user,
            panelId: PanelResolver::normalizeId($panelId),
            contextType: $this->contextType,
            contextId: $this->contextId,
            ttlSeconds: $this->ttlSeconds,
            expiresAt: $this->expiresAt,
        );
    }

    #[Override]
    public function inContext(string $contextType, int|string $contextId): static
    {
        return new self(
            user: $this->user,
            panelId: $this->panelId,
            contextType: $contextType,
            contextId: $contextId,
            ttlSeconds: $this->ttlSeconds,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * Set TTL in seconds. null = no expiry. Clears any until() (last wins).
     */
    #[Override]
    public function ttl(?int $seconds): static
    {
        return new self(
            user: $this->user,
            panelId: $this->panelId,
            contextType: $this->contextType,
            contextId: $this->contextId,
            ttlSeconds: $seconds,
            expiresAt: null,
        );
    }

    /**
     * Set an absolute expiry timestamp. null = no expiry. Clears any ttl()
     * (last wins).
     */
    #[Override]
    public function until(?DateTimeInterface $at): static
    {
        return new self(
            user: $this->user,
            panelId: $this->panelId,
            contextType: $this->contextType,
            contextId: $this->contextId,
            ttlSeconds: null,
            expiresAt: $at,
        );
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Grant a permission in the current context (or update expires_at on an
     * existing one). Idempotent: repeated calls update expires_at only —
     * parity with the core GrantBuilder.
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     * @throws InvalidArgumentException When $permission is the wildcard key or
     *                                  contains a wildcard metacharacter — a
     *                                  context grant is scoped by design and
     *                                  must never carry superadmin/broad reach.
     */
    #[Override]
    public function grant(string|UnitEnum $permission): ContextRole
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();
        $permissionKey = PermissionName::resolve($permission, $panel);

        if (str_contains($permissionKey, PermissionKey::WILDCARD)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot grant wildcard permission key [%s] in a context grant — context grants are '
                .'scoped by design. Grant superadmin/broad permissions panel-wide via '
                .'AzGuard::forUser()->grant() instead.',
                $permissionKey,
            ));
        }

        $expiresAt = $this->expiresAt
            ?? ($this->ttlSeconds !== null ? Carbon::now()->addSeconds($this->ttlSeconds) : null);

        /** @var ContextRole $contextRole */
        $contextRole = ContextRole::query()->updateOrCreate(
            [
                'model_type' => $this->user->getMorphClass(),
                'model_id' => $this->user->getAuthIdentifier(),
                'context_type' => $contextType,
                'context_id' => $contextId,
                'panel_id' => $panel,
                'permission_key' => $permissionKey,
            ],
            ['expires_at' => $expiresAt],
        );

        event(new ContextGrantGiven(
            user: $this->user,
            permissionKey: $permissionKey,
            panelId: $panel,
            contextType: $contextType,
            contextId: $contextId,
            contextRole: $contextRole,
        ));

        return $contextRole;
    }

    /**
     * Revoke a specific permission in the current context.
     *
     * @return int Number of deleted records (0 or 1).
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     */
    #[Override]
    public function revoke(string|UnitEnum $permission): int
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();
        $permissionKey = PermissionName::resolve($permission, $panel);

        $deleted = $this->baseQuery($panel, $contextType, $contextId)
            ->where('permission_key', $permissionKey)
            ->delete();

        if ($deleted > 0) {
            event(new ContextGrantRevoked(
                user: $this->user,
                permissionKey: $permissionKey,
                panelId: $panel,
                contextType: $contextType,
                contextId: $contextId,
            ));
        }

        return (int) $deleted;
    }

    /**
     * Revoke every permission the user holds in the current context+panel.
     *
     * @return int Number of deleted records.
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     */
    #[Override]
    public function revokeAll(): int
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();

        $deleted = $this->baseQuery($panel, $contextType, $contextId)->delete();

        if ($deleted > 0) {
            event(new ContextGrantRevoked(
                user: $this->user,
                permissionKey: PermissionKey::WILDCARD,
                panelId: $panel,
                contextType: $contextType,
                contextId: $contextId,
            ));
        }

        return (int) $deleted;
    }

    /**
     * Return all active (non-expired) context grants for the user in the
     * current context+panel — parity with the core GrantBuilder::grants().
     *
     * @return Collection<int, ContextRole>
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     */
    #[Override]
    public function grants(): Collection
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();

        return $this->baseQuery($panel, $contextType, $contextId)
            ->active()
            ->get();
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int|string}
     *
     * @throws ContextNotSetException
     */
    private function resolveContextOrFail(): array
    {
        if ($this->contextType === null || $this->contextId === null) {
            throw new ContextNotSetException;
        }

        return [$this->contextType, $this->contextId];
    }

    /**
     * @return Builder<ContextRole>
     */
    private function baseQuery(string $panel, string $contextType, int|string $contextId): Builder
    {
        return ContextRole::query()
            ->where('model_type', $this->user->getMorphClass())
            ->where('model_id', $this->user->getAuthIdentifier())
            ->where('panel_id', $panel)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId);
    }
}
