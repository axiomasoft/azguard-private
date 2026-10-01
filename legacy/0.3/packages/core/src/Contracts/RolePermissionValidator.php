<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

/**
 * Validates a role permission key on save. Opt-in (config
 * `az-guard.features.validate_role_permissions`, default off = lenient) and
 * swappable (config `az-guard.role_permission_validator`), so an unlinted
 * wildcard or typo'd key can be rejected before it silently grants access.
 *
 * @api
 */
interface RolePermissionValidator
{
    /**
     * Throw to reject an invalid key; return silently to accept.
     *
     * @throws InvalidPermissionKeyException
     */
    public function validate(string $permissionKey, string $panelId): void;
}
