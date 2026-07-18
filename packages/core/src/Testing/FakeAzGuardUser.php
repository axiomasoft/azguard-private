<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Contracts\HasPermissions;
use AzGuard\Contracts\PermissionContext;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\PanelResolver;
use AzGuard\Support\PermissionName;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Override;
use Throwable;
use UnitEnum;

/**
 * A dependency-free user double for testing AzGuard integrations — no database,
 * migrations, panels or catalog. Holds an in-memory permission set and answers
 * the permission APIs against it.
 *
 *   $user = (new FakeAzGuardUser)->grant('app', DocumentsPermission::View);
 *   $user->hasPermission(DocumentsPermission::View);   // true
 *   $user->isSuperAdmin();                             // false
 *
 *   (new FakeAzGuardUser)->wildcard()->isSuperAdmin(); // true
 *
 * Type-hint {@see HasPermissions} (or Authenticatable) where you accept this in
 * an adapter under test. It intentionally does NOT provide roles/relations
 * (those need Eloquent) — use a real Eloquent user with {@see HasAzGuard}
 * when you need role or scoped-role behavior. hasPermissionIn() always returns
 * false here (no context backend).
 *
 * @api
 */
final class FakeAzGuardUser implements Authenticatable, HasPermissions
{
    /** @var array<string, list<string>> panelId => resolved permission keys */
    private array $granted = [];

    private bool $wildcard = false;

    public function __construct(
        private readonly int|string $id = 1,
    ) {}

    /**
     * Grant permissions (enum cases, Permission classes or full keys) on a panel.
     */
    public function grant(string|BackedEnum $panelId, string|UnitEnum ...$permissions): self
    {
        $panelId = PanelResolver::normalizeId($panelId);

        foreach ($permissions as $permission) {
            $this->granted[$panelId][] = PermissionName::resolve($permission, $panelId);
        }

        return $this;
    }

    /**
     * Grant everything (super-admin) on every panel.
     */
    public function wildcard(): self
    {
        $this->wildcard = true;

        return $this;
    }

    // ─── HasPermissions ────────────────────────────────────────────────────────

    #[Override]
    public function permissionSet(string|BackedEnum|null $panelId = null): PermissionSet
    {
        if ($this->wildcard) {
            return PermissionSet::wildcard();
        }

        $panelId = PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId));

        return PermissionSet::fromKeys($this->granted[$panelId] ?? []);
    }

    #[Override]
    public function hasPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool
    {
        $panelId = PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId));

        return $this->permissionSet($panelId)->grants(PermissionName::resolve($permission, $panelId));
    }

    #[Override]
    public function hasPermissionIn(string $contextType, int|string $contextId, string|UnitEnum $permission, string|BackedEnum|null $panelId = null): bool
    {
        return false;
    }

    #[Override]
    public function checkPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool
    {
        try {
            return $this->hasPermission($permission, $panelId, $context);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return Collection<int, string> */
    #[Override]
    public function permissions(string|BackedEnum|null $panelId = null): Collection
    {
        return collect($this->permissionSet($panelId)->keys());
    }

    #[Override]
    public function isSuperAdmin(string|BackedEnum|null $panelId = null): bool
    {
        return $this->permissionSet($panelId)->isWildcard();
    }

    #[Override]
    public function flushPermissions(string|BackedEnum|null $panelId = null): void
    {
        // In-memory; nothing to flush.
    }

    #[Override]
    public function hasContextGuard(): bool
    {
        return false;
    }

    // ─── Authenticatable ───────────────────────────────────────────────────────

    #[Override]
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    #[Override]
    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    #[Override]
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    #[Override]
    public function getAuthPassword(): string
    {
        return '';
    }

    #[Override]
    public function getRememberToken(): string
    {
        return '';
    }

    #[Override]
    public function setRememberToken($value): void
    {
        // No-op for the fake.
    }

    #[Override]
    public function getRememberTokenName(): string
    {
        return '';
    }
}
