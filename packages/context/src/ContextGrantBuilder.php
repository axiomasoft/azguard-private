<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Context\Events\ContextGrantGiven;
use AzGuard\Context\Events\ContextGrantRevoked;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\PermissionKey;
use AzGuard\Support\PanelResolver;
use AzGuard\Support\PermissionName;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Fluent write-API for context-scoped grants (az_guard_context_roles).
 *
 * The context-package counterpart to AzGuard\Grants\GrantBuilder: that
 * builder writes panel-wide direct grants, this one writes grants scoped
 * to an (contextType, contextId) pair — e.g. "workspace #42".
 *
 * Usage:
 *   (new ContextGrantBuilder($user))
 *       ->on('app')
 *       ->inContext('workspace', 42)
 *       ->grant('app.documents.export');
 *
 *   (new ContextGrantBuilder($user))
 *       ->on('app')
 *       ->inContext('workspace', 42)
 *       ->revoke('app.documents.export');
 */
final class ContextGrantBuilder
{
    private ?string $panelId = null;

    private ?string $contextType = null;

    private int|string|null $contextId = null;

    public function __construct(
        private readonly Authenticatable $user,
    ) {}

    // ─── Fluent setters ───────────────────────────────────────────────────────

    public function on(string|BackedEnum $panelId): static
    {
        $this->panelId = PanelResolver::normalizeId($panelId);

        return $this;
    }

    public function inContext(string $contextType, int|string $contextId): static
    {
        $this->contextType = $contextType;
        $this->contextId = $contextId;

        return $this;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Grant a permission in the current context.
     * Idempotent: repeated calls are a no-op (unique index on the row).
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     */
    public function grant(string|UnitEnum $permission): ContextRole
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();
        $permissionKey = PermissionName::resolve($permission, $panel);

        /** @var ContextRole $contextRole */
        $contextRole = ContextRole::query()->firstOrCreate([
            'model_type' => $this->user::class,
            'model_id' => $this->user->getAuthIdentifier(),
            'context_type' => $contextType,
            'context_id' => $contextId,
            'panel_id' => $panel,
            'permission_key' => $permissionKey,
        ]);

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
     * Return all context grants for the user in the current context+panel.
     *
     * @return Collection<int, ContextRole>
     *
     * @throws PanelNotSetException
     * @throws ContextNotSetException
     */
    public function grants(): Collection
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        [$contextType, $contextId] = $this->resolveContextOrFail();

        return $this->baseQuery($panel, $contextType, $contextId)->get();
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
            ->where('model_type', $this->user::class)
            ->where('model_id', $this->user->getAuthIdentifier())
            ->where('panel_id', $panel)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId);
    }
}
