<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Changes;

use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Schema\PermissionSchema;

/**
 * The permissions of one panel and tenant. Permissions of enums live in code and are only listed; dynamic permissions
 * exist only when the database writer of the panel declares `dynamicPermissions()`, belong to the tenant and are
 * always decided by grants. Every change goes through the change pipeline.
 *
 * @api
 */
interface PermissionManager
{
    /**
     * Static and dynamic permissions of the tenant, ordered by name.
     *
     * @return list<PermissionSchema>
     */
    public function all(): array;

    /**
     * Creates a dynamic permission of the tenant.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PanelNotWritableException without `dynamicPermissions()`
     * @throws DuplicatePermissionException when the name is taken, also by a permission of an enum
     */
    public function create(string $name, ?string $label = null, ?string $group = null, array $fields = []): ChangeResult;

    /**
     * Replaces label, group, description and fields of a dynamic permission of the tenant.
     *
     * @throws UnknownPermissionException for a static or missing name
     */
    public function update(string $name, PermissionDetails $details): ChangeResult;

    /**
     * Deletes a dynamic permission of the tenant with every exact grant of it, in one mutation; patterns stay.
     *
     * @throws UnknownPermissionException for a static or missing name
     */
    public function delete(string $name): ChangeResult;
}
