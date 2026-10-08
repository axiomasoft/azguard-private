<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Configuration;

use AzGuard\Storage\Models\RoleGrant;
use Illuminate\Database\Eloquent\Attributes\Connection;

#[Connection('secondary')]
final class AttributedConnectionRoleGrant extends RoleGrant {}
