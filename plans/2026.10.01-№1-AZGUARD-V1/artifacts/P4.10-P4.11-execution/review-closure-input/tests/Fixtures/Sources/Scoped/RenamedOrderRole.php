<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;

#[Role('renamed')]
#[FormerKeys('orders')]
final class RenamedOrderRole extends OrderRole {}
