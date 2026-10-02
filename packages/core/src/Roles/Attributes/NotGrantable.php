<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;

/**
 * The role is only granted automatically and never assigned by hand.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class NotGrantable {}
