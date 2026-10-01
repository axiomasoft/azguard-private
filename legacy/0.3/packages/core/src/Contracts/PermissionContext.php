<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

/**
 * Minimal contract for a one-off contextual permission check.
 *
 * Passed as the $context argument to hasPermission() / checkPermission().
 * The context package's AuthorizationContext implements this interface.
 *
 * @api
 */
interface PermissionContext
{
    public function contextType(): string;

    public function contextId(): int|string;
}
