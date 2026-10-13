<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;

/** The consumer's scope type: stores 1 and 2 of the global tenant. */
final class ConsumerScope implements AssignmentScopeDefinition
{
    public function type(): string
    {
        return 'store';
    }

    public function model(): ?string
    {
        return null;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        return $ref->type() === 'store' && in_array($ref->id(), ['1', '2'], true)
            ? new ResolvedAssignmentScope($ref, TenantRef::global())
            : null;
    }
}
