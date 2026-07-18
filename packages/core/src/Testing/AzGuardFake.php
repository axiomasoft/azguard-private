<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;
use AzGuard\Facades\AzGuard;
use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use AzGuard\Permissions\PermissionName;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Assert as PHPUnit;
use UnitEnum;

/**
 * Recording double of {@see AzGuardManagerInterface}, installed by
 * {@see AzGuard::fake()}.
 *
 * Every manager method is delegated to the real, already-booted manager —
 * grants still persist and checks still run through the real resolver;
 * fake() observes, it does not replace behavior. Recording works by
 * listening for {@see GrantGiven}/{@see GrantRevoked} (dispatched by
 * {@see GrantBuilder} regardless of whether the fluent root or the
 * positional shorthand was used) and by a `Gate::after()` hook (fired for
 * every Gate/`can()` check, whatever decided it).
 *
 * @api
 */
final class AzGuardFake implements AzGuardManagerInterface, Fake
{
    /** @var list<Recorded> */
    private array $granted = [];

    /** @var list<Recorded> */
    private array $revoked = [];

    /** @var list<Recorded> */
    private array $checked = [];

    public function __construct(private readonly AzGuardManagerInterface $manager)
    {
        Event::listen(GrantGiven::class, function (GrantGiven $event): void {
            $this->granted[] = new Recorded($event->user, $event->permissionKey, $event->panelId);
        });

        Event::listen(GrantRevoked::class, function (GrantRevoked $event): void {
            $this->revoked[] = new Recorded($event->user, $event->permissionKey, $event->panelId);
        });

        Gate::after(function ($user, string $ability, ?bool $result): void {
            if ($user instanceof Authenticatable) {
                $this->checked[] = new Recorded($user, $ability, result: $result);
            }
        });
    }

    // ─── Assertions ─────────────────────────────────────────────────────────

    /**
     * Assert a grant was recorded for $user+$key (+$panelId, default panel
     * otherwise). Closure form: assertGranted(fn (Recorded $r) => …).
     */
    public function assertGranted(Authenticatable|Closure $user, string|UnitEnum|null $key = null, string|BackedEnum|null $panelId = null): void
    {
        $this->assertRecorded($this->granted, $user, $key, $panelId, 'No matching grant was recorded.');
    }

    /**
     * Assert a revoke was recorded for $user+$key (+$panelId, default panel
     * otherwise). Closure form: assertDenied(fn (Recorded $r) => …).
     */
    public function assertDenied(Authenticatable|Closure $user, string|UnitEnum|null $key = null, string|BackedEnum|null $panelId = null): void
    {
        $this->assertRecorded($this->revoked, $user, $key, $panelId, 'No matching revoke was recorded.');
    }

    /**
     * Assert a Gate/`can()` check was recorded for $key (any user, any
     * panel — the closure form has both available on the Recorded).
     * Closure form: assertChecked(fn (Recorded $r) => …).
     */
    public function assertChecked(string|UnitEnum|Closure $key): void
    {
        if ($key instanceof Closure) {
            $found = false;

            foreach ($this->checked as $record) {
                if ($key($record) === true) {
                    $found = true;

                    break;
                }
            }

            PHPUnit::assertTrue($found, 'No matching check was recorded.');

            return;
        }

        $resolvedKey = $key instanceof UnitEnum
            ? PermissionName::resolve($key, PanelResolver::resolveDefault(null))
            : $key;

        $found = false;

        foreach ($this->checked as $record) {
            if ($record->key === $resolvedKey) {
                $found = true;

                break;
            }
        }

        PHPUnit::assertTrue($found, "No check was recorded for [{$resolvedKey}].");
    }

    /**
     * @param  list<Recorded>  $records
     */
    private function assertRecorded(array $records, Authenticatable|Closure $user, string|UnitEnum|null $key, string|BackedEnum|null $panelId, string $failureMessage): void
    {
        if ($user instanceof Closure) {
            $found = false;

            foreach ($records as $record) {
                if ($user($record) === true) {
                    $found = true;

                    break;
                }
            }

            PHPUnit::assertTrue($found, $failureMessage);

            return;
        }

        if ($key === null) {
            throw new InvalidArgumentException('A permission key is required unless the closure form is used.');
        }

        $resolvedPanelId = PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId));
        $resolvedKey = PermissionName::resolve($key, $resolvedPanelId);

        $found = false;

        foreach ($records as $record) {
            if ($this->sameUser($record->user, $user) && $record->key === $resolvedKey && $record->panelId === $resolvedPanelId) {
                $found = true;

                break;
            }
        }

        PHPUnit::assertTrue($found, $failureMessage);
    }

    private function sameUser(Authenticatable $a, Authenticatable $b): bool
    {
        return $a::class === $b::class && $a->getAuthIdentifier() === $b->getAuthIdentifier();
    }

    // ─── AzGuardManagerInterface — delegate to the real manager ─────────────

    #[Override]
    public function registerPanel(Panel|callable $panel): void
    {
        $this->manager->registerPanel($panel);
    }

    /**
     * @return array<string, Panel>
     */
    #[Override]
    public function getPanels(): array
    {
        return $this->manager->getPanels();
    }

    #[Override]
    public function panel(string|BackedEnum $id): ?Panel
    {
        return $this->manager->panel($id);
    }

    #[Override]
    public function currentPanel(): ?Panel
    {
        return $this->manager->currentPanel();
    }

    #[Override]
    public function setCurrentPanel(?Panel $panel): void
    {
        $this->manager->setCurrentPanel($panel);
    }

    #[Override]
    public function permission(string|BackedEnum $panelId, string|UnitEnum $permission): string
    {
        return $this->manager->permission($panelId, $permission);
    }

    #[Override]
    public function tryPermission(string|BackedEnum $panelId, string|UnitEnum $permission): ?string
    {
        return $this->manager->tryPermission($panelId, $permission);
    }

    #[Override]
    public function panelIdForPermission(UnitEnum $permission): ?string
    {
        return $this->manager->panelIdForPermission($permission);
    }

    #[Override]
    public function isSuperAdmin(Authenticatable $user, string|BackedEnum|null $panelId = null): bool
    {
        return $this->manager->isSuperAdmin($user, $panelId);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    #[Override]
    public function abilitiesFor(Authenticatable $user, string|BackedEnum|null $panelId, array $keys): array
    {
        return $this->manager->abilitiesFor($user, $panelId, $keys);
    }

    #[Override]
    public function hasContextGuard(): bool
    {
        return $this->manager->hasContextGuard();
    }

    /**
     * @param  class-string<GrantSource>  $sourceClass
     */
    #[Override]
    public function registerGrantSource(string $sourceClass): void
    {
        $this->manager->registerGrantSource($sourceClass);
    }

    /**
     * @param  class-string<PermissionCatalogBuilder>  $builderClass
     */
    #[Override]
    public function registerCatalogBuilder(string $builderClass): void
    {
        $this->manager->registerCatalogBuilder($builderClass);
    }

    #[Override]
    public function forUser(Authenticatable $user): GrantBuilder
    {
        return $this->manager->forUser($user);
    }

    #[Override]
    public function grant(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
        ?int $ttl = null,
    ): DirectGrant {
        return $this->manager->grant($user, $permissionKey, $panelId, $ttl);
    }

    #[Override]
    public function revoke(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
    ): int {
        return $this->manager->revoke($user, $permissionKey, $panelId);
    }

    /**
     * @return Collection<int, DirectGrant>
     */
    #[Override]
    public function grants(
        Authenticatable $user,
        string|BackedEnum|null $panelId = null,
    ): Collection {
        return $this->manager->grants($user, $panelId);
    }
}
