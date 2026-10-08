<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleGrantResource\Pages;

use AzGuard\Filament\Editors\ListGrants;
use AzGuard\Filament\Resources\RoleGrantResource;

/**
 * The role grants of one managed panel and tenant.
 *
 * @internal
 */
final class ListRoleGrants extends ListGrants
{
    protected static string $resource = RoleGrantResource::class;

    protected static function kind(): string
    {
        return 'role';
    }
}
