<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\PermissionGrantResource\Pages;

use AzGuard\Filament\Internal\Editors\ListGrants;
use AzGuard\Filament\Resources\PermissionGrantResource;
use Packman\Attributes\Internal;

/**
 * The permission grants of one managed panel and tenant.
 *
 * @internal
 */
#[Internal('Filament list page for permission grants.')]
final class ListPermissionGrants extends ListGrants
{
    protected static string $resource = PermissionGrantResource::class;

    protected static function kind(): string
    {
        return 'permission';
    }
}
