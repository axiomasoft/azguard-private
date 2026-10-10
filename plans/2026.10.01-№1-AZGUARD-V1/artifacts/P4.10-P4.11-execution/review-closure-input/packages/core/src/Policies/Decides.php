<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use Attribute;
use UnitEnum;

/**
 * Binds one policy method to a permission. The method name is not a permission name.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Decides
{
    public function __construct(public UnitEnum|string $permission) {}
}
