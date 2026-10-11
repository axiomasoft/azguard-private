<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Attributes\Api;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

/**
 * One assignment scope a directory offers to the interface.
 */
#[Api(since: '0.7')]
final readonly class AssignmentScopeOption
{
    public function __construct(
        public AssignmentScopeRef $scope,
        public string $label,
        public ?string $description = null,
    ) {}
}
