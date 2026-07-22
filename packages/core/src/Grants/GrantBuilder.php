<?php

declare(strict_types=1);

namespace AzGuard\Grants;

use AzGuard\Contracts\ContextGrantBuilder;
use AzGuard\Contracts\ContextGrantBuilderFactory;
use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;
use AzGuard\Exceptions\ContextPackageNotInstalledException;
use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\PanelResolver;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Permissions\PermissionName;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Immutable fluent builder for working with Direct Grants — the single
 * grant root shared by core and the optional context package.
 *
 * Every scope setter (on/until/ttl) returns a NEW instance; branches from
 * one builder never see each other's state. inContext() hands the chain
 * over to the context branch ({@see ContextGrantBuilder}).
 *
 * Usage:
 *   AzGuard::forUser($user)->on('app')->ttl(3600)->grant('app.documents.export');
 *   AzGuard::forUser($user)->on('app')->revoke('app.documents.export');
 *   AzGuard::forUser($user)->on('app')->grants();
 *   AzGuard::forUser($user)->on('app')->inContext('workspace', 42)->grant('app.posts.edit');
 */
final readonly class GrantBuilder
{
    public function __construct(
        private Authenticatable $user,
        private ?string $panelId = null,
        private ?int $ttlSeconds = null,
        private ?DateTimeInterface $expiresAt = null,
    ) {}

    // ─── Fluent setters (immutable-with) ─────────────────────────────────────

    public function on(string|BackedEnum $panelId): self
    {
        return new self(
            user: $this->user,
            panelId: PanelResolver::normalizeId($panelId),
            ttlSeconds: $this->ttlSeconds,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * Set TTL in seconds. null = no expiry. Clears any until() (last wins).
     */
    public function ttl(?int $seconds): self
    {
        return new self(
            user: $this->user,
            panelId: $this->panelId,
            ttlSeconds: $seconds,
        );
    }

    /**
     * Set an absolute expiry timestamp. null = no expiry. The DateTime-based
     * counterpart to ttl() — for parity with HasDirectGrants::grant($expiresAt).
     * Clears any ttl() (last wins).
     */
    public function until(?DateTimeInterface $at): self
    {
        return new self(
            user: $this->user,
            panelId: $this->panelId,
            expiresAt: $at,
        );
    }

    /**
     * Extend the chain into a context: hand accumulated scope (user, panel,
     * expiry) over to the context branch of the grammar, provided by the
     * optional azguard/context package.
     *
     * @throws ContextPackageNotInstalledException When azguard/context is absent.
     */
    public function inContext(string $contextType, int|string $contextId): ContextGrantBuilder
    {
        if (! app()->bound(ContextGrantBuilderFactory::class)) {
            throw new ContextPackageNotInstalledException;
        }

        $builder = app(ContextGrantBuilderFactory::class)->forUser($this->user);

        if ($this->panelId !== null) {
            $builder = $builder->on($this->panelId);
        }

        if ($this->ttlSeconds !== null) {
            $builder = $builder->ttl($this->ttlSeconds);
        }

        if ($this->expiresAt instanceof DateTimeInterface) {
            $builder = $builder->until($this->expiresAt);
        }

        return $builder->inContext($contextType, $contextId);
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
