<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\TenantRef;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The tenant of the current Filament panel as the tenant of the guard panel.
 *
 * The application attaches it with `tenantResolvers([FilamentTenantResolver::class])` on the guard panel. It reads the
 * tenant that Filament chose, takes its type from the tenant model of the guard panel and never invents a tenant: a
 * request without a Filament tenant, a Filament panel without the plugin or a tenant of another model gives null.
 *
 * @api
 */
final class FilamentTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?TenantRef
    {
        $tenant = Filament::getTenant();
        $filament = Filament::getCurrentPanel();

        if ($tenant === null || $filament === null || ! $filament->hasPlugin(AzGuardPlugin::ID)) {
            return null;
        }

        $plugin = $filament->getPlugin(AzGuardPlugin::ID);

        if (! $plugin instanceof AzGuardPlugin) {
            return null;
        }

        $definition = (AzGuard::panels()[$plugin->getGuardPanel()] ?? null)?->tenants()->definition();

        if ($definition === null || ! $tenant instanceof ($definition->model())) {
            return null;
        }

        return TenantRef::of($definition->type(), $tenant->getKey());
    }
}
