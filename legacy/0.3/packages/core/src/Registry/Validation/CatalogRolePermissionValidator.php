<?php

declare(strict_types=1);

namespace AzGuard\Registry\Validation;

use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;
use Override;

/**
 * Default {@see RolePermissionValidator}: rejects any key that is not an exact
 * member of the panel catalog — which includes stray '*' rows and typos that
 * would otherwise become un-linted grants.
 */
final readonly class CatalogRolePermissionValidator implements RolePermissionValidator
{
    public function __construct(private PermissionCatalog $catalog) {}

    #[Override]
    public function validate(string $permissionKey, string $panelId): void
    {
        if (! $this->catalog->has($panelId, $permissionKey)) {
            throw InvalidPermissionKeyException::forKey($permissionKey, $panelId);
        }
    }
}
