<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

/**
 * Who decides a permission: its policy alone, or assignments with an optional policy veto.
 */
enum PermissionAuthority: string
{
    case Policy = 'policy';
    case Grants = 'grants';
}
