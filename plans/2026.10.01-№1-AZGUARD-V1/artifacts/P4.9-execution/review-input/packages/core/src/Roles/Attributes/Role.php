<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;

/**
 * Stable key, label and display level of a code role; the label may be a translation key.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Role
{
    public function __construct(
        public ?string $key = null,
        public ?string $label = null,
        public int $level = 0,
    ) {}
}
