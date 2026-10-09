<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Directories\QueryScopeDirectory;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopePhase;

final class ContextAwareProjectDirectory implements AssignmentScopeDirectory
{
    /** @var list<LookupContext> */
    public array $lookups = [];

    public function search(string $type, string $term, LookupContext $lookup, int $limit): array
    {
        return (new QueryScopeDirectory(app()))->search($type, $term, $lookup, $limit);
    }

    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
    {
        $this->lookups[] = $lookup;

        if ($lookup->phase === AssignmentScopePhase::Assignment && ($lookup->subject === null || $lookup->role === null)) {
            return null;
        }

        return (new QueryScopeDirectory(app()))->describe($context, $lookup);
    }
}
