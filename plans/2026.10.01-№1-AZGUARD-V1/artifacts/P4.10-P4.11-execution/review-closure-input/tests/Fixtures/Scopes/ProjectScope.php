<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ProjectScope implements QueryableAssignmentScopeDefinition
{
    public function type(): string
    {
        return 'crm.project';
    }

    public function model(): string
    {
        return Project::class;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        if ($ref->type() !== $this->type() || $ref->id() === null) {
            return null;
        }

        $record = $this->query()->whereKey($ref->id())->first();

        return $record === null ? null : new ResolvedAssignmentScope($ref, $this->tenantOf($record), $record);
    }

    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        return Project::query();
    }

    public function tenantOf(Model $record): TenantRef
    {
        $organization = $record->getAttribute('organization_id');

        return TenantRef::of('crm.organization', is_int($organization) || is_string($organization) ? $organization : '');
    }
}
