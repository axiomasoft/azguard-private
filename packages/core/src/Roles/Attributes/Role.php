<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * Stable key, label and display level of a code role; the label may be a translation key.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class Role
{
    public function __construct(
        public ?string $key = null,
        public ?string $label = null,
        public int $level = 0,
    ) {}
}
