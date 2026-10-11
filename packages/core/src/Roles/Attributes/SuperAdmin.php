<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * The holder of this role is a super admin.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '0.7')]
final readonly class SuperAdmin {}
