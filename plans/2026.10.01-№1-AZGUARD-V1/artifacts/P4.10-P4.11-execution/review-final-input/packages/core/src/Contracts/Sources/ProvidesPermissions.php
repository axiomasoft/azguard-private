<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/**
 * A source of permissions for the catalog of a panel.
 *
 * @spi
 */
interface ProvidesPermissions extends Source
{
    /**
     * Permissions of the panel; a static source is read once while the panel is built, with `$tenant = null`.
     *
     * @return iterable<PermissionDefinition>
     */
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable;

    /**
     * False: the permissions are known when the application boots and go to `azguard:catalog:cache`. True: they
     * change while the application runs and are read per tenant; such permissions are always decided by grants.
     */
    public function isDynamic(): bool;
}
