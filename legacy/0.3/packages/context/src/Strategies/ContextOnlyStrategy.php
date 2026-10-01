<?php

declare(strict_types=1);

namespace AzGuard\Context\Strategies;

use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;
use Override;

/**
 * Strategy: context permissions only.
 *
 * Global permissions are ignored entirely.
 * If no context is set — an empty PermissionSet.
 *
 * Suitable for strict isolation:
 * a user outside a context has no permissions,
 * even if they hold a global role.
 */
final class ContextOnlyStrategy implements MergeStrategy
{
    #[Override]
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        return $context ?? PermissionSet::empty();
    }
}
