<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes;

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ProjectScope extends BaseAssignmentScope
{
    public static ?string $failure = null;

    public static function make(): self
    {
        return new self;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        if (self::$failure === 'resolve') {
            throw new RuntimeException('project resolver unavailable');
        }

        return parent::resolve($ref);
    }

    public function type(): string
    {
        return 'crm.project';
    }

    public function query(): Builder
    {
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
}
