<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;

/**
 * Label, group and description of one permission case.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Describe
{
    public function __construct(
        public string $label,
        public ?string $group = null,
        public ?string $description = null,
    ) {}
}
