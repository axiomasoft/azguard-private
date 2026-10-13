<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Attributes\Api;

/**
 * "In every assignment scope": a revocation target only, never a place to grant.
 */
#[Api(since: '1.0')]
final readonly class AnyAssignmentScope
{
    private function __construct() {}

    public static function all(): self
    {
        return new self;
    }
}
