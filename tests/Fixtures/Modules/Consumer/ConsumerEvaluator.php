<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;

/** The consumer's scope evaluator: store 2 is closed, every other store is eligible. */
final class ConsumerEvaluator implements AssignmentScopeEvaluator
{
    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        return $resolved->ref->id() !== '2';
    }

    public function allowsMany(array $checks): array
    {
        return array_map(fn (array $check): bool => $this->allows($check[0], $check[1]), $checks);
    }
}
