<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\Panel;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * A drop-in manager double an integrator might bind via config('az-guard.manager')
 * to decorate behaviour. It delegates every call to a real inner manager, so it
 * is a faithful substitute — the F5 swap test only asserts the container resolves
 * THIS class through AzGuardManagerInterface (and the facade).
 */
final class SwapTestManager implements AzGuardManagerInterface
{
    private AzGuardManager $inner;

    public function __construct()
    {
        $this->inner = new AzGuardManager;
    }

    public function registerPanel(Panel|callable $panel): void
    {
        $this->inner->registerPanel($panel);
    }

    public function getPanels(): array
    {
        return $this->inner->getPanels();
    }

    public function panel(string|BackedEnum $id): ?Panel
    {
        return $this->inner->panel($id);
    }

    public function currentPanel(): ?Panel
    {
        return $this->inner->currentPanel();
    }

    public function setCurrentPanel(?Panel $panel): void
    {
        $this->inner->setCurrentPanel($panel);
    }

    public function permission(string|BackedEnum $panelId, string|UnitEnum $permission): string
    {
        return $this->inner->permission($panelId, $permission);
    }

    public function tryPermission(string|BackedEnum $panelId, string|UnitEnum $permission): ?string
    {
        return $this->inner->tryPermission($panelId, $permission);
    }

    public function panelIdForPermission(UnitEnum $permission): ?string
    {
        return $this->inner->panelIdForPermission($permission);
    }

    public function isSuperAdmin(Authenticatable $user, string|BackedEnum|null $panelId = null): bool
    {
        return $this->inner->isSuperAdmin($user, $panelId);
    }

    public function abilitiesFor(Authenticatable $user, string|BackedEnum|null $panelId, array $keys): array
    {
        return $this->inner->abilitiesFor($user, $panelId, $keys);
    }

    public function hasContextGuard(): bool
    {
        return $this->inner->hasContextGuard();
    }

    public function registerGrantSource(string $sourceClass): void
    {
        $this->inner->registerGrantSource($sourceClass);
    }

    public function registerCatalogBuilder(string $builderClass): void
    {
        $this->inner->registerCatalogBuilder($builderClass);
    }

    public function forUser(Authenticatable $user): GrantBuilder
    {
        return $this->inner->forUser($user);
    }

    public function grant(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
        ?int $ttl = null,
    ): DirectGrant {
        return $this->inner->grant($user, $permissionKey, $panelId, $ttl);
    }

    public function revoke(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
    ): int {
        return $this->inner->revoke($user, $permissionKey, $panelId);
    }

    public function grants(
        Authenticatable $user,
        string|BackedEnum|null $panelId = null,
    ): Collection {
        return $this->inner->grants($user, $panelId);
    }
}
