<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use RuntimeException;

/** Fails on an ordinary request. */
final class ThrowingEvaluator implements AssignmentScopeEvaluator
{
    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        throw new RuntimeException('The evaluator is unavailable.');
    }

    public function allowsMany(array $checks): array
    {
        throw new RuntimeException('The evaluator is unavailable.');
    }
}
