<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\Permission;
use UnitEnum;

/**
 * Resolves a permission argument (string or enum) to its stored catalog key.
 *
 * A string is treated as an already-resolved full key and returned unchanged.
 * An enum is scoped to the panel exactly like the catalog
 * (via Panel::resolvePermission on the registered panel), so an enum case
 * `DocumentsPermission::View = 'documents.view'` resolves to "app.documents.view".
 * When the panel is not registered, the enum falls back to its raw value/name.
 */
final class PermissionName
{
    public static function resolve(string|UnitEnum $permission, string $panelId): string
    {
        // A plain key string is already resolved. A Permission class-string is
        // not — it still needs scoping via its owning panel.
        if (is_string($permission) && ! PermissionGrammar::isPermissionClass($permission)) {
            PermissionGrammar::assertValid($permission);

            return $permission;
        }

        $resolved = app(AzGuardManagerInterface::class)->tryPermission($panelId, $permission);

        if ($resolved !== null) {
            PermissionGrammar::assertValid($resolved);

            return $resolved;
        }

        // Panel not registered — best-effort unscoped fallback.
        if ($permission instanceof UnitEnum) {
            $fallback = PermissionKey::normalize($permission);
        } else {
            /** @var class-string<Permission> $permission */
            $fallback = $permission::ability();
        }

        PermissionGrammar::assertValid($fallback);

        return $fallback;
    }
}
