<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ProjectScope extends BaseAssignmentScope implements FiltersAccessQueries
{
    public static ?string $failure = null;

    public static function make(): self
    {
        return new self;
    }

    public function type(): string
    {
        return 'crm.project';
    }

    public function query(): Builder
    {
        // Keep the nominal descriptor on the inherited resolver; inject failures at its actual query seam.
        if (self::$failure === 'resolve') {
            throw new RuntimeException('project resolver unavailable');
        }

        if (self::$failure === 'query') {
            throw new RuntimeException('project directory unavailable');
        }

        return Project::query();
    }

    public function tenantOf(Model $record): TenantRef
    {
        if (self::$failure === 'owner') {
            throw new RuntimeException('project owner unavailable');
        }

        return TenantRef::of('crm.organization', (string) $record->getAttribute('organization_id'));
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        if (self::$failure === 'owner') {
            throw new RuntimeException('project owner unavailable');
        }

        return P::eq('organization_id', $context->scope()->tenant->id());
    }
}
