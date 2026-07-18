<?php

declare(strict_types=1);

namespace AzGuard\Grants;

use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;
use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Models\DirectGrant;
use AzGuard\PermissionKey;
use AzGuard\Support\PanelResolver;
use AzGuard\Support\PermissionName;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Fluent builder for working with Direct Grants.
 *
 * Usage:
 *   AzGuard::forUser($user)->on('app')->ttl(3600)->grant('app.documents.export');
 *   AzGuard::forUser($user)->on('app')->revoke('app.documents.export');
 *   AzGuard::forUser($user)->on('app')->grants();
 */
final class GrantBuilder
{
    private ?string $panelId = null;

    private ?int $ttlSeconds = null;

    private ?DateTimeInterface $expiresAt = null;

    public function __construct(
        private readonly Authenticatable $user,
    ) {}

    // ─── Fluent setters ───────────────────────────────────────────────────────

    public function on(string|BackedEnum $panelId): static
    {
        $this->panelId = PanelResolver::normalizeId($panelId);

        return $this;
    }

    /**
     * Set TTL in seconds. null = no expiry. Clears any expiresAt() (last wins).
     */
    public function ttl(?int $seconds): static
    {
        $this->ttlSeconds = $seconds;
        $this->expiresAt = null;

        return $this;
    }

    /**
     * Set an absolute expiry timestamp. null = no expiry. The DateTime-based
     * counterpart to ttl() — for parity with HasDirectGrants::grant($expiresAt).
     * Clears any ttl() (last wins).
     */
    public function expiresAt(?DateTimeInterface $at): static
    {
        $this->expiresAt = $at;
        $this->ttlSeconds = null;

        return $this;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Grant a permission (or update expires_at on an existing one).
     * Idempotent: repeated calls update expires_at only.
     *
     * @throws PanelNotSetException
     */
    public function grant(string|UnitEnum $permission): DirectGrant
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        $permissionKey = PermissionName::resolve($permission, $panel);

        $expiresAt = $this->expiresAt
            ?? ($this->ttlSeconds !== null ? Carbon::now()->addSeconds($this->ttlSeconds) : null);

        /** @var DirectGrant $grant */
        $grant = DirectGrant::query()->updateOrCreate(
            [
                'grantable_type' => $this->user->getMorphClass(),
                'grantable_id' => $this->user->getAuthIdentifier(),
                'panel_id' => $panel,
                'permission_key' => $permissionKey,
            ],
            ['expires_at' => $expiresAt],
        );

        event(new GrantGiven(
            user: $this->user,
            permissionKey: $permissionKey,
            panelId: $panel,
            grant: $grant,
        ));

        return $grant;
    }

    /**
     * Revoke a specific permission.
     *
     * @return int Number of deleted records (0 or 1).
     *
     * @throws PanelNotSetException
     */
    public function revoke(string|UnitEnum $permission): int
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        $permissionKey = PermissionName::resolve($permission, $panel);
        $deleted = $this->baseQuery($panel)
            ->where('permission_key', $permissionKey)
            ->delete();

        if ($deleted > 0) {
            event(new GrantRevoked(
                user: $this->user,
                permissionKey: $permissionKey,
                panelId: $panel,
            ));
        }

        return (int) $deleted;
    }

    /**
     * Revoke all permissions for the user on the panel.
     *
     * @return int Number of deleted records.
     *
     * @throws PanelNotSetException
     */
    public function revokeAll(): int
    {
        $panel = PanelResolver::resolveOrFail($this->panelId);
        $deleted = $this->baseQuery($panel)->delete();

        if ($deleted > 0) {
            event(new GrantRevoked(
                user: $this->user,
                permissionKey: PermissionKey::WILDCARD,
                panelId: $panel,
            ));
        }

        return (int) $deleted;
    }

    /**
     * Return all active grants for the user on the panel.
     *
     * @return Collection<int, DirectGrant>
     *
     * @throws PanelNotSetException
     */
    public function grants(): Collection
    {
        return $this->baseQuery(PanelResolver::resolveOrFail($this->panelId))
            ->active()
            ->get();
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    /**
     * Base query scoped to this user + panel.
     * All mutating / read methods build on top of this.
     *
     * @return Builder<DirectGrant>
     */
    private function baseQuery(string $panel): Builder
    {
        return DirectGrant::query()
            ->where('grantable_type', $this->user->getMorphClass())
            ->where('grantable_id', $this->user->getAuthIdentifier())
            ->where('panel_id', $panel);
    }
}
