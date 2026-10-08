<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Configuration;

use AzGuard\Storage\Models\RoleGrant;

final class WrongTableRoleGrant extends RoleGrant
{
    protected $table = 'other';
}
