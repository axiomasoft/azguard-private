<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Packman\Attributes\Api;

/**
 * One assignment scope a directory offers to the interface.
 */
#[Api(since: '1.0')]
final readonly class AssignmentScopeOption
{
    public function __construct(
        public AssignmentScopeRef $scope,
        public string $label,
        public ?string $description = null,
    ) {}
}
