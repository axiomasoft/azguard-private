<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\BaseAssignmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A configured project scope. The structural {@see ProjectScope} fixture stays without this base.
 */
final class ConfiguredProjectScope extends BaseAssignmentScope
{
    public static function make(): static
    {
        return new self;
    }

    public function type(): string
    {
        return 'crm.project';
    }

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
