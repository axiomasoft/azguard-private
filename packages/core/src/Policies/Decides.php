<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use Attribute;
use AzGuard\Attributes\Api;
use UnitEnum;

/**
 * Binds one policy method to a permission. The method name is not a permission name.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0')]
final readonly class Decides
{
    public function __construct(public UnitEnum|string $permission) {}
}
