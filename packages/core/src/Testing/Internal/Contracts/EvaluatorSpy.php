<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use Packman\Attributes\Internal;

#[Internal]
final class EvaluatorSpy implements AssignmentScopeEvaluator
{
    /** @var list<string> */
    private array $asked = [];

    public function __construct(private readonly AssignmentScopeEvaluator $evaluator) {}

    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void
    {
        $this->evaluator->validate($definition, $registered);
    }

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        $this->asked[] = $resolved->ref->key();

        return $this->evaluator->allows($runtime, $resolved);
    }

    public function allowsMany(array $checks): array
    {
        foreach ($checks as [, $resolved]) {
            $this->asked[] = $resolved->ref->key();
        }

        return $this->evaluator->allowsMany($checks);
    }

    /** @return list<string> */
    public function drainAsked(): array
    {
        $asked = $this->asked;
        $this->asked = [];

        return $asked;
    }
}
