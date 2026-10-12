<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Testing\Contracts\AssignmentScopeEvaluatorContractTests;
use AzGuard\Tests\Fixtures\Contracts\ContractStoreScope;
use AzGuard\Tests\Fixtures\Scopes\RecordingEvaluator;
use AzGuard\Tests\TestCase;

final class RecordingEvaluatorContractTest extends TestCase
{
    use AssignmentScopeEvaluatorContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingEvaluator::reset();
        RecordingEvaluator::$denied = ['2'];
    }

    protected function azguardEvaluator(): AssignmentScopeEvaluator
    {
        return new RecordingEvaluator;
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
