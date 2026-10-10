<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Catalog;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Identity\PermissionKey;
use UnitEnum;

/**
 * Permissions of one panel: the static part built when the application boots and, for a tenant, its dynamic part.
 *
 * A name is the local name of the panel (`orders.view`) or a permission key of the panel; a key of another panel is
 * never found. Every lookup reads a hash index: no list is scanned and no query is made.
 *
 * @api
 */
interface PermissionCatalog
{
    /**
     * Id of the panel the catalog belongs to.
     */
    public function panel(): string;

    public function has(PermissionKey|string $permission): bool;

    public function find(PermissionKey|string $permission): ?PermissionDefinition;

    /**
     * @throws UnknownPermissionException
     */
    public function get(PermissionKey|string $permission): PermissionDefinition;

    /**
     * The key of the permission an enum case names on this panel.
     *
     * @throws UnknownPermissionException when the case names no permission of the panel
     */
    public function keyOf(UnitEnum $case): PermissionKey;

    /**
     * @return array<string, PermissionDefinition> definitions by local name, in the order they were contributed
     */
    public function all(): array;
}
