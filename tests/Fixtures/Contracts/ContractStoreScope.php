<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Modules\Scopes\Fixtures\StoreScope;

/** The scope type of the evaluator suites: stores 1 and 2 of the global tenant. */
final class ContractStoreScope
{
    public static function definition(): StoreScope
    {
        return new StoreScope(static fn (AssignmentScopeRef $ref): ?ResolvedAssignmentScope => $ref->type() === 'store' && in_array($ref->id(), ['1', '2'], true)
            ? new ResolvedAssignmentScope($ref, TenantRef::global())
            : null);
    }

    /** @return non-empty-list<AssignmentScopeRef> */
    public static function scopes(): array
    {
        return [AssignmentScopeRef::of('store', 1), AssignmentScopeRef::of('store', 2)];
    }
}
