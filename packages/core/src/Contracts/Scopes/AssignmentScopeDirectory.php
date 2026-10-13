<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

/**
 * Search of assignment scopes of one type for the interface.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeDirectory
{
    /**
     * @return list<AssignmentScopeOption>
     */
    public function search(string $type, string $term, LookupContext $lookup, int $limit): array;

    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption;
}
