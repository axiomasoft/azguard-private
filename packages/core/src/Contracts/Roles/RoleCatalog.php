<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Roles;

use AzGuard\Roles\BaseRole;
use AzGuard\Schema\RoleSchema;

/**
 * The code roles of one panel, read-only. A role is a PHP class: its key, permissions and scopes change only in code
 * and with a deployment, never through this catalog. Who holds a role is a matter of grants, not of the catalog.
 *
 * @api
 */
interface RoleCatalog
{
    /**
     * Every code role of the panel, ordered by key.
     *
     * @return list<RoleSchema>
     */
    public function all(): array;

    /**
     * A code role by key, by `panel:key` of this panel or by the class of a registered role. A former key, a key of
     * another panel or an unregistered class is not found: a former key is never an alias of the current one.
     *
     * @param  string|class-string<BaseRole>  $key
     */
    public function find(string $key): ?RoleSchema;
}
