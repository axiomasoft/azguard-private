<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Context;

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\Contracts\ResolvesContext;
use Illuminate\Http\Request;

/**
 * Test resolver: always yields a fixed workspace context for the 'test' panel,
 * so a Feature test can assert the azguard.context middleware set the context
 * on the manager without any application-specific request parsing.
 */
final class StaticWorkspaceResolver implements ResolvesContext
{
    public function resolve(Request $request): ?AuthorizationContext
    {
        return new AuthorizationContext('test', 'workspace', 42);
    }

    public function panelId(): string
    {
        return 'test';
    }
}
