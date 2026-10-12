<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Testing\Contracts\AssignmentScopeEvaluatorContractTests;
use AzGuard\Tests\Fixtures\Contracts\ContractStoreScope;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class EvaluatorProbe
{
    use AssignmentScopeEvaluatorContractTests;
    use RunsAsAProbe;

    /** @param Closure(): AssignmentScopeEvaluator $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardEvaluator(): AssignmentScopeEvaluator
    {
        return ($this->make)();
    }

    protected function azguardScopeDefinition(): AssignmentScopeDefinition
    {
        return ContractStoreScope::definition();
    }

    protected function azguardScopes(): array
    {
        return ContractStoreScope::scopes();
    }
}
