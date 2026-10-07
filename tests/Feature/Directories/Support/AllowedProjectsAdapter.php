<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class AllowedProjectsAdapter implements AssignmentScopeAccessAdapter
{
    /** @var list<AssignmentScopeRuntime> */
    public static array $observed = [];

    /** @var list<string> */
    public static array $allowed = ['1', '2'];

    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
    {
        return $this->allowsMany([$ref], $runtime)[$ref->key()];
    }

    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
    {
        self::$observed[] = $runtime;
        $result = [];

        foreach ($refs as $ref) {
            $result[$ref->key()] = in_array($ref->id(), self::$allowed, true);
        }

        return $result;
    }

    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
    {
        throw new RuntimeException('Directories check candidates, not a context query.');
    }
}
