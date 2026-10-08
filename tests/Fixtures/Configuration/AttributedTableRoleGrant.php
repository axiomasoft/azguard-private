<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Configuration;

use AzGuard\Storage\Models\RoleGrant;
use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('other')]
final class AttributedTableRoleGrant extends RoleGrant {}
