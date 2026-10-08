<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Resolvers;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Testing\Contracts\AssignmentScopeResolverContractTests;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use AzGuard\Tests\TestCase;
use Illuminate\Http\Request;

final class AssignmentScopeResolverContractTest extends TestCase
{
    use AssignmentScopeResolverContractTests;

    protected function azguardScopeResolver(): AssignmentScopeResolver
    {
        return new GoodScopeResolver;
    }

    protected function azguardScopedRequests(): array
    {
        return [Request::create('/', server: ['HTTP_X_STORE' => '5']), Request::create('/orders', server: ['HTTP_X_STORE' => 'north-2'])];
    }
}
