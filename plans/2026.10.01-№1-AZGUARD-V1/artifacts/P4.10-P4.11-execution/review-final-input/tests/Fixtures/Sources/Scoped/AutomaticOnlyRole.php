<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;

#[Role('orders')]
#[NotGrantable]
final class AutomaticOnlyRole extends OrderRole {}
