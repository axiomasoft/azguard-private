<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Real HTTP requests over the CRM stand: the `web` guard authenticates the user named by `actingAs()` (the stand's
 * users are plain models, as with a custom request guard), the organization comes from `X-Tenant` and the chosen
 * project from `X-Project`.
 */
final class HttpWorld
{
    public static ?int $user = null;

    public static bool $organization = false;

    /** Middleware every test route runs before the AzGuard middleware: route model binding. */
    public const array BINDINGS = [SubstituteBindings::class];

    public static function seed(): void
    {
        CrmWorld::seed();
        self::authenticate();
    }

    /** The HTTP side alone, over a stand that is seeded already. */
    public static function authenticate(): void
    {
        self::$user = null;
        self::$organization = false;
        HttpMemberRole::$users = [];
        EntryPolicy::$allow = true;
        Auth::viaRequest('azguard-http', static function (Request $request): ?object {
            if (self::$user === null) {
                return null;
            }

            return self::$organization ? Organization::query()->find(self::$user) : User::query()->find(self::$user);
        });
        config(['auth.guards.web' => ['driver' => 'azguard-http'], 'auth.defaults.guard' => 'web', 'app.debug' => false]);
        Auth::forgetGuards();
    }

    /**
     * The crm panel resolving the tenant and the project from the request, with the policy-only entry permission that
     * only this panel has; the stand's own options on top.
     */
    public static function panel(?Closure $configure = null, ?array $sources = null): Panel
    {
        return CrmWorld::compile(static function (PanelBuilder $panel) use ($configure): void {
            $panel->tenantResolvers([new HeaderTenantResolver])->scopeResolvers([new HeaderProjectResolver])
                ->permissions([EntryPermission::class])->policies([EntryPolicy::class]);

            if ($configure !== null) {
                $configure($panel);
            }
        }, $sources);
    }

    /** The next request is made by this user of the stand, or by a guest. */
    public static function actingAs(?int $user, bool $organization = false): void
    {
        self::$user = $user;
        self::$organization = $organization;
        Auth::forgetGuards();
    }

    /** Makes a user of the stand a member of an organization without any role there. */
    public static function member(int $user, int $tenant = 1): void
    {
        DB::table('organization_user')->insertOrIgnore(['organization_id' => $tenant, 'user_id' => $user]);
    }

    /** Moves every role grant of the user into the past: the grants expired a day ago. */
    public static function expire(int $user): void
    {
        CrmWorld::storage()->mutate('crm', static function (StorageMutation $mutation) use ($user): void {
            $mutation->table('role_grants')->where('subject_id', (string) $user)->update(['expires_at' => '2026-10-05 12:00:00']);
            $mutation->touch('crm');
        });
    }
}
