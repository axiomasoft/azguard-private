<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class EligibilityExternalAdapter implements AssignmentScopeAccessAdapter
{
    public array $observed = [];

    public function __construct(public ?Closure $predicate = null) {}

    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
    {
        $this->observed[] = $runtime;

        return $this->predicate === null || ($this->predicate)($ref, $runtime);
    }

    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
    {
        $result = [];
        foreach ($refs as $ref) {
            $result[$ref->key()] = $this->allows($ref, $runtime);
        }

        return $result;
    }

    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
    {
        throw new RuntimeException('External scalar eligibility must never call query constrain.');
    }
}
