<?php

declare(strict_types=1);

namespace AzGuard\Context\Contracts;

use AzGuard\Registry\Values\PermissionSet;

/**
 * Strategy for merging global and context permissions.
 *
 * @see GlobalPlusContextStrategy  global ∪ context
 * @see ContextOnlyStrategy        context only
 * @see DenyWithoutContextStrategy deny when no context is present
 */
interface MergeStrategy
{
    /**
     * @param  PermissionSet  $global  permissions from global sources (ClassRole, DatabaseRole)
     * @param  PermissionSet|null  $context  permissions from the context (null = no context set)
     */
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet;
}
