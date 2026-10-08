<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;

final class KitStoreScope implements AssignmentScopeDefinition
{
    public function type(): string
    {
        return 'store';
    }

    public function model(): ?string
    {
        return KitStore::class;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        return $ref->type() === 'store' ? new ResolvedAssignmentScope($ref, TenantRef::global()) : null;
    }
}
