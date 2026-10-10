<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;

final class CrmRoleGrant extends RoleGrant
{
    public static function azguardFields(): array
    {
        return [Field::string('region')->inMeta(), Field::bool('eligible')->inMeta()];
    }
}
