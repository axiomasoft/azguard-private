<?php

declare(strict_types=1);

namespace AzGuard\Context\Strategies;

use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;
use Override;

/**
 * Strategy: deny everything without a context; with a context — global ∪ context.
 *
 * If no context is set — returns an empty PermissionSet,
 * blocking all access.
 * If a context is set — behaves like GlobalPlusContextStrategy.
 *
 * Suitable for panels where working outside a context
 * (e.g. outside the selected project) is semantically impossible.
 */
final class DenyWithoutContextStrategy implements MergeStrategy
{
    #[Override]
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        if (! $context instanceof PermissionSet) {
            return PermissionSet::empty();
        }

        return $global->merge($context);
    }
}
