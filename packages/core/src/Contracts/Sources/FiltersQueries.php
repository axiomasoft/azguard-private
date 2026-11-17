<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Raw assignment candidates and witnesses; null means exact selection is unsupported.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FiltersQueries extends Source
{
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?AssignmentScopeSelection;
}
