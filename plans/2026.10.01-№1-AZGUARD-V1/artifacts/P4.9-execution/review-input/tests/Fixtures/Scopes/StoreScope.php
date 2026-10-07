<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Closure;

class StoreScope implements AssignmentScopeDefinition
{
    public int $resolves = 0;

    public function __construct(public ?Closure $resolveUsing = null) {}

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
        $this->resolves++;

        if ($this->resolveUsing !== null) {
            return ($this->resolveUsing)($ref);
        }

        return $ref->type() === 'store' && in_array($ref->id(), ['1', '2'], true)
            ? new ResolvedAssignmentScope($ref, TenantRef::of('org', $ref->id() === '1' ? 'A' : 'B'))
            : null;
    }
}
