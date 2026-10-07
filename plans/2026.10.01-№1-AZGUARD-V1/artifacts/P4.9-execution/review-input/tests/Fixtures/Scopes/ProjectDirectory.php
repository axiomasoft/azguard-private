<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

final class ProjectDirectory implements AssignmentScopeDirectory
{
    public function search(string $type, string $term, LookupContext $lookup, int $limit): array
    {
        return [];
    }

    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
    {
        return null;
    }
}
