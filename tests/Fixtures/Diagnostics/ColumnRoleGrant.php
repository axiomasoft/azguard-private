<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;

/** A role grant model that keeps its field in a column of its own. */
final class ColumnRoleGrant extends RoleGrant
{
    public static function azguardFields(): array
    {
        return [Field::string('region')];
    }
}
