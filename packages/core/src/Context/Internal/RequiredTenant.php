<?php

declare(strict_types=1);

namespace AzGuard\Context\Internal;

use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Tenancy\TenantMode;

/** @internal The tenant of an access: the one given, the current context, or the global tenant of a panel without tenants. */
final class RequiredTenant
{
    private function __construct() {}

    /** @throws TenantRequiredException */
    public static function of(Panel $panel, ?TenantRef $explicit): TenantRef
    {
        $tenant = $explicit ?? app(CurrentContext::class)->get($panel)?->tenant;

        if ($tenant !== null) {
            return $tenant;
        }

        if ($panel->tenants()->mode() === TenantMode::None) {
            return TenantRef::global();
        }

        throw new TenantRequiredException('Panel '.$panel->id().' has tenants: choose one with inTenant(); nothing is read or changed across tenants.');
    }
}
