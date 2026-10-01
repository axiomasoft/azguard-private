<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when `azguard.check` reaches a controller action that has neither
 * `#[CheckPermission]` nor `#[SkipGuardCheck]` and
 * `az-guard.require_permission_attributes` is enabled.
 */
final class MissingPermissionAttributeException extends AzGuardException
{
    public function __construct(public readonly string $action)
    {
        parent::__construct(
            "AzGuard CheckAccess reached [{$action}] without #[CheckPermission] or #[SkipGuardCheck]. "
            .'Add an attribute or disable az-guard.require_permission_attributes.',
        );
    }
}
