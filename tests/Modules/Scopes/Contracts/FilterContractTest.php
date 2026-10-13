<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Contracts;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Testing\AssignmentScopeFilterContractTests;
use AzGuard\Tests\Modules\Scopes\Fixtures\ActiveProjects;
use AzGuard\Tests\Modules\Scopes\Fixtures\EligibilityProjectScope;
use AzGuard\Tests\Modules\Scopes\Fixtures\EligibilityWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\FilterRuntime;
use AzGuard\Tests\TestCase;

/** The module's own filter fixture through the supported contract suite. */
final class FilterContractTest extends TestCase
{
    use AssignmentScopeFilterContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        EligibilityWorld::tables();
    }

    protected function azguardFilter(): AssignmentScopeFilter
    {
        return new ActiveProjects;
    }

    protected function azguardDefinition(): QueryableAssignmentScopeDefinition
    {
        return EligibilityProjectScope::make();
    }

    protected function azguardRuntime(): AssignmentScopeRuntime
    {
        return FilterRuntime::of($this->azguardDefinition());
    }
}
