<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

/**
 * "In every assignment scope": a revocation target only, never a place to grant.
 */
final readonly class AnyAssignmentScope
{
    private function __construct() {}

    public static function all(): self
    {
        return new self;
    }
}
