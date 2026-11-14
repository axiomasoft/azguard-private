<?php

declare(strict_types=1);

namespace AzGuard\Attributes\Internal;

/**
 * Whether `#[CheckPermission]` applies to an action of the controller.
 *
 * @internal
 */
trait DescribesPermissionCheck
{
    /**
     * Whether the check applies to the action, by `only` and `except` the way Laravel filters controller middleware.
     */
    public function appliesTo(string $action): bool
    {
        return ($this->only === null || in_array($action, $this->only, true))
            && ($this->except === null || $this->except === [] || ! in_array($action, $this->except, true));
    }
}
