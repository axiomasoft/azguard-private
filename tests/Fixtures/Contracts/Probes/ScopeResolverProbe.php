<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Testing\Contracts\AssignmentScopeResolverContractTests;
use Closure;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class ScopeResolverProbe
{
    use AssignmentScopeResolverContractTests;
    use RunsAsAProbe;

    /** @param Closure(): AssignmentScopeResolver $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardScopeResolver(): AssignmentScopeResolver
    {
        return ($this->make)();
    }

    protected function azguardScopedRequests(): array
    {
        return [Request::create('/', server: ['HTTP_X_STORE' => '5'])];
    }
}
