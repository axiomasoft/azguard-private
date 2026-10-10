<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ProjectDefinition implements QueryableAssignmentScopeDefinition
{
    public int $resolutions = 0;

    public bool $brokenQuery = false;

    public ?Model $resolvedRecord = null;

    /** @param class-string<Model>|null $recordClass */
    public function __construct(
        private readonly string $alias = 'project',
        private readonly ?string $recordClass = Project::class,
        private readonly bool $global = false,
        private readonly ?TenantRef $resolvedTenant = null,
    ) {}

    public function type(): string
    {
        return $this->alias;
    }

    public function model(): ?string
    {
        return $this->recordClass;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        $this->resolutions++;

        if ($ref->type() !== $this->type() || $ref->id() === null) {
            return null;
        }
        $record = $this->query()->whereKey($ref->id())->first();

        return $record === null ? null : new ResolvedAssignmentScope($ref, $this->resolvedTenant ?? $this->tenantOf($record), $this->resolvedRecord ?? $record);
    }

    /** @return Builder<Model> */
    public function query(): Builder
    {
        if ($this->brokenQuery || $this->recordClass === null) {
            throw new RuntimeException('Relation fixture query failed.');
        }

        return $this->recordClass::query();
    }

    public function tenantOf(Model $record): TenantRef
    {
        return $this->global ? TenantRef::global() : TenantRef::of('organization', (string) $record->getAttribute('tenant_id'));
    }
}
