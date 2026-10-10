<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Raw assignment candidates and witnesses; null means exact selection is unsupported.
 *
 * @spi
 */
interface FiltersQueries extends Source
{
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?AssignmentScopeSelection;
}
