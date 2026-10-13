<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Testing\AssignmentScopeFilterContractTests;
use AzGuard\Tests\Fixtures\Contracts\Probes\RunsAsAProbe;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class FilterProbe
{
    use AssignmentScopeFilterContractTests;
    use RunsAsAProbe;

    /** @param Closure(): AssignmentScopeFilter $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardFilter(): AssignmentScopeFilter
    {
        return ($this->make)();
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
