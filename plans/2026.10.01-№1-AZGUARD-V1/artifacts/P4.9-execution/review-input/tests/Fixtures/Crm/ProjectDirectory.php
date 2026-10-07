<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Crm\Models\Project;

final class ProjectDirectory implements AssignmentScopeDirectory
{
    public function search(string $type, string $term, LookupContext $lookup, int $limit): array
    {
        if ($type !== 'crm.project') {
            return [];
        }

        return Project::query()->where('organization_id', $lookup->scope->tenant->id())->where('id', 'like', '%'.$term.'%')->limit($limit)->get()
            ->map(fn (Project $p): AssignmentScopeOption => new AssignmentScopeOption(AssignmentScopeRef::of($type, $p->getKey()), 'P'.$p->getKey()))->all();
    }

    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
    {
        $record = Project::query()->where('organization_id', $lookup->scope->tenant->id())->find($context->id());

        return $record === null ? null : new AssignmentScopeOption($context, 'P'.$record->getKey());
    }
}
