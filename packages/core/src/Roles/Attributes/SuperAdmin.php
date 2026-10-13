<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * The holder of this role is a super admin.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class SuperAdmin {}
