<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * Label, group and description of one permission case.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
#[Api(since: '0.7')]
final readonly class Describe
{
    public function __construct(
        public string $label,
        public ?string $group = null,
        public ?string $description = null,
    ) {}
}
