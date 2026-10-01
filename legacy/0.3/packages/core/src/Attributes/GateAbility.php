<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;
use UnitEnum;

#[Attribute(flags: Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class GateAbility
{
    public function __construct(
        public UnitEnum $permission,
    ) {}
}
