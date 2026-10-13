<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use Attribute;
use AzGuard\Attributes\Api;
use UnitEnum;

/**
 * Binds a policy class to one permission enum when the folder group does not decide the pair.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class PolicyFor
{
    /**
     * @param  class-string<UnitEnum>  $permissions
     */
    public function __construct(public string $permissions) {}
}
