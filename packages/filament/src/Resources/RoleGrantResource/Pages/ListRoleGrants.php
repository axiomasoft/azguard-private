<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleGrantResource\Pages;

use AzGuard\Filament\Internal\Editors\ListGrants;
use AzGuard\Filament\Resources\RoleGrantResource;
use Packman\Attributes\Internal;

/**
 * The role grants of one managed panel and tenant.
 *
 * @internal
 */
#[Internal('Filament list page for role grants.')]
final class ListRoleGrants extends ListGrants
{
    protected static string $resource = RoleGrantResource::class;

    protected static function kind(): string
    {
        return 'role';
    }
}
