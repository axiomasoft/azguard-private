<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;

final class DatabaseRoleGrant extends RoleGrant
{
    public static function azguardFields(): array
    {
        return [Field::int('score'), Field::int('weekdays')->multiple()->inMeta(), Field::string('private_note')->inMeta()];
    }

    protected $casts = ['score' => 'integer'];
}
