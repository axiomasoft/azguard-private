<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Permissions;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Kernel\Decision\PermissionAuthority;

/**
 * Two grants permissions and one decided by its policy alone, as P2.8 will read them from attributes.
 */
enum ClientPermission: string
{
    case View = 'clients.view';
    case Update = 'clients.update';
    case ViewOwnProfile = 'clients.view_own_profile';

    /**
     * @return list<PermissionDefinition>
     */
    public static function definitions(): array
    {
        return array_map(static fn (self $case): PermissionDefinition => new PermissionDefinition(
            local: $case->value,
            authority: $case === self::ViewOwnProfile ? PermissionAuthority::Policy : PermissionAuthority::Grants,
            label: $case->name,
            group: 'clients',
            case: $case,
        ), self::cases());
    }
}
