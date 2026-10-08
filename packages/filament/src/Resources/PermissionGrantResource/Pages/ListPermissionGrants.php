<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\PermissionGrantResource\Pages;

use AzGuard\Filament\Editors\ListGrants;
use AzGuard\Filament\Resources\PermissionGrantResource;

/**
 * The permission grants of one managed panel and tenant.
 *
 * @internal
 */
final class ListPermissionGrants extends ListGrants
{
    protected static string $resource = PermissionGrantResource::class;

    protected static function kind(): string
    {
        return 'permission';
    }
}
