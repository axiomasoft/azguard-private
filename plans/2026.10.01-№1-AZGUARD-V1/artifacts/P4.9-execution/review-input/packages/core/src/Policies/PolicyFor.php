<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use Attribute;
use UnitEnum;

/**
 * Binds a policy class to one permission enum when the folder group does not decide the pair.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PolicyFor
{
    /**
     * @param  class-string<UnitEnum>  $permissions
     */
    public function __construct(public string $permissions) {}
}
