<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Tests\Fixtures\Storage\Department;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/** The role grant model of the `admin` panel with a field of its own: the department, kept in `meta`. */
final class AdminRoleGrant extends RoleGrant
{
    protected $casts = ['meta' => AsArrayObject::class];

    public static function azguardFields(): array
    {
        return [Field::model('department_id', Department::class)->label('Department')->inMeta()];
    }
}
