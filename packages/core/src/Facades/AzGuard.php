<?php

declare(strict_types=1);

namespace AzGuard\Facades;

use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Testing\AzGuardFake;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use Override;
use UnitEnum;

/**
 * @method static void registerPanel(Panel|callable $panel)
 * @method static array<string, Panel> getPanels()
 * @method static Panel|null panel(string|BackedEnum $id)
 * @method static Panel|null currentPanel()
 * @method static void setCurrentPanel(?Panel $panel)
 * @method static string permission(string|BackedEnum $panelId, (string | UnitEnum) $permission)
 * @method static string|null tryPermission(string|BackedEnum $panelId, (string | UnitEnum) $permission)
 * @method static string|null panelIdForPermission(UnitEnum $permission)
 * @method static void registerGrantSource(class-string<GrantSource> $sourceClass)
 * @method static void registerCatalogBuilder(class-string<PermissionCatalogBuilder> $builderClass)
 *
 * --- Actor API ---
 * @method static bool isSuperAdmin(Authenticatable $user, (string | BackedEnum | null) $panelId = null)
 * @method static array<string, bool> abilitiesFor(Authenticatable $user, (string | BackedEnum | null) $panelId, array<int, string> $keys)
 * @method static bool hasContextGuard()
 *
 * --- Grants API ---
 * The public grant path is the fluent root: AzGuard::forUser($user)->on(...)->grant(...).
 * The positional shorthands below (grant/revoke/grants) are @internal twins kept for
 * internal orchestration — see AzGuardManagerInterface.
 * @method static GrantBuilder forUser(Authenticatable $user)
 * @method static DirectGrant grant(Authenticatable $user, (string | UnitEnum) $permissionKey, (string | BackedEnum | null) $panelId = null, ?int $ttl = null)
 * @method static int revoke(Authenticatable $user, (string | UnitEnum) $permissionKey, (string | BackedEnum | null) $panelId = null)
 * @method static Collection<int, DirectGrant> grants(Authenticatable $user, (string | BackedEnum | null) $panelId = null)
 *
 * --- Testing ---
 * @method static void assertGranted((Authenticatable | Closure) $user, (string | UnitEnum | null) $key = null, (string | BackedEnum | null) $panelId = null)
 * @method static void assertDenied((Authenticatable | Closure) $user, (string | UnitEnum | null) $key = null, (string | BackedEnum | null) $panelId = null)
 * @method static void assertChecked((string | UnitEnum | Closure) $key)
 *
 * @see AzGuardManager
 *
 * @api
 */
final class AzGuard extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return AzGuardManagerInterface::class;
    }

    /**
     * Swap the manager for a recording double: grants/checks still run for
     * real — fake() observes, it does not replace behavior. Read what was
     * recorded via assertGranted()/assertDenied()/assertChecked().
     */
    public static function fake(): AzGuardFake
    {
        $fake = new AzGuardFake(app(AzGuardManagerInterface::class));

        self::swap($fake);

        return $fake;
    }
}
