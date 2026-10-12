<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Testing\Contracts\AssignmentScopeEvaluatorContractTests;
use AzGuard\Tests\Fixtures\Contracts\ContractStoreScope;
use AzGuard\Tests\TestCase;

final class ScopeEvaluatorContractTest extends TestCase
{
    use AssignmentScopeEvaluatorContractTests;

    protected function azguardEvaluator(): AssignmentScopeEvaluator
    {
        return new ScopeEvaluator(app());
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
