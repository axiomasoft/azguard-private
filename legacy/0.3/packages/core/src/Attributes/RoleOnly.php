<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

#[Attribute(flags: Attribute::TARGET_CLASS_CONSTANT)]
final readonly class RoleOnly {}
