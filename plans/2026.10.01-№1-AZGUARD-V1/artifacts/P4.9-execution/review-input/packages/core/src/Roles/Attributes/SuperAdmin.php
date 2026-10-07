<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;

/**
 * The holder of this role is a super admin.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SuperAdmin {}
