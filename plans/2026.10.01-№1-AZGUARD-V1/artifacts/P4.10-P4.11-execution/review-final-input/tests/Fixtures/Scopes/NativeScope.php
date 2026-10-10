<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\BaseAssignmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class NativeScope extends BaseAssignmentScope
{
    public function type(): string
    {
        return 'native.project';
    }

    /** @return Builder<Model> */
    public function query(): Builder
    {
        return NativeProject::query();
    }

    public function tenantOf(Model $record): TenantRef
    {
        return TenantRef::of('org', $record->getAttribute('owner'));
    }
}
