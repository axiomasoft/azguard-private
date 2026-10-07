<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class BatchExternalAdapter implements AssignmentScopeAccessAdapter
{
    public bool $batchOnly = false;

    /** @var list<AssignmentScopeRuntime> */
    public array $observed = [];

    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
    {
        if ($this->batchOnly) {
            throw new RuntimeException('Batch external eligibility must use allowsMany.');
        }

        return $this->eligible($runtime);
    }

    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
    {
        $this->observed[] = $runtime;
        $result = [];

        foreach ($refs as $ref) {
            $result[$ref->key()] = $this->eligible($runtime);
        }

        return $result;
    }

    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
    {
        throw new RuntimeException('External definitions have no native query.');
    }

    private function eligible(AssignmentScopeRuntime $runtime): bool
    {
        return $runtime->grant === null || ($runtime->grant->fields()['eligible'] ?? false);
    }
}
