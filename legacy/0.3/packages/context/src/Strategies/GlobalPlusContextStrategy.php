<?php

declare(strict_types=1);

namespace AzGuard\Context\Strategies;

use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;
use Override;

/**
 * Strategy: global ∪ context.
 *
 * The user gets the union of global permissions and context permissions.
 * If no context is set — global permissions only.
 *
 * Suitable for most multi-workspace scenarios:
 * a platform administrator holds global permissions,
 * a workspace member holds the permissions of their workspace.
 */
final class GlobalPlusContextStrategy implements MergeStrategy
{
    #[Override]
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        if (! $context instanceof PermissionSet) {
            return $global;
        }

        return $global->merge($context);
    }
}
