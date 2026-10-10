<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Storage;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

final class AdminRoleGrant extends RoleGrant
{
    protected $casts = ['meta' => AsArrayObject::class];

    public static function azguardFields(): array
    {
        return [Field::model('department_id', Department::class)->required(),
            Field::enum('weekdays', Weekday::class)->multiple()->inMeta()];
    }
}
