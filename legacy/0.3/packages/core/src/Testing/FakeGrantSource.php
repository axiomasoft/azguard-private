<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Panels\PanelResolver;
use AzGuard\Permissions\PermissionName;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;
use UnitEnum;

/**
 * A GrantSource for tests: grant a fixed set of permissions to every user,
 * without setting up roles, DB rows or direct grants.
 *
 *   $fake = (new FakeGrantSource)->grant('app', DocumentsPermission::View);
 *   app()->instance(FakeGrantSource::class, $fake);
 *   AzGuard::registerGrantSource(FakeGrantSource::class);
 *
 *   // now any user passes:
 *   $user->hasPermission(DocumentsPermission::View);   // true
 *
 * Use ->wildcard() to grant everything (like a super-admin).
 *
 * @api
 */
final class FakeGrantSource implements GrantSource
{
    /** @var array<string, list<string>> panelId => resolved permission keys */
    private array $granted = [];

    private bool $wildcard = false;

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
     * Grant everything on every panel (bypasses all checks, like a super-admin).
     */
    public function wildcard(): self
    {
        $this->wildcard = true;

        return $this;
    }

    #[Override]
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        if ($this->wildcard) {
            return PermissionSet::wildcard();
        }

        return PermissionSet::fromKeys($this->granted[$panelId] ?? []);
    }

    #[Override]
    public function priority(): int
    {
        // Above the built-in sources so fakes win during tests.
        return 1000;
    }
}
