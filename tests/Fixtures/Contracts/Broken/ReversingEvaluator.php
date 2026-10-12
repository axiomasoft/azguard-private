<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;

/** Breaks the order of `allowsMany()`: the answers come back reversed. */
final class ReversingEvaluator implements AssignmentScopeEvaluator
{
    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        return $resolved->ref->id() === '1';
    }

    public function allowsMany(array $checks): array
    {
        return array_reverse(array_map(fn (array $check): bool => $this->allows($check[0], $check[1]), $checks));
    }
}
