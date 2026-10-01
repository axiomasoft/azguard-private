<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;
use UnitEnum;

#[Attribute(flags: Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class CheckPermission
{
    /**
     * @param  list<string>  $arguments
     */
    public function __construct(
        public UnitEnum $permission,
        public array $arguments = [],
        public int $status = 403,
        public ?string $message = null,
    ) {}
}
