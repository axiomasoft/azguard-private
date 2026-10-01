<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

#[Attribute(flags: Attribute::TARGET_CLASS)]
final readonly class GuardPolicy
{
    public function __construct(
        public string $model,
    ) {}
}
