<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Contracts\ContextGrantBuilder as ContextGrantBuilderContract;
use AzGuard\Contracts\ContextGrantBuilderFactory as ContextGrantBuilderFactoryContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Hands the core fluent root over to the context branch: bound in the
 * container by AzGuardContextServiceProvider so that core's
 * GrantBuilder::inContext() can create a ContextGrantBuilder without core
 * knowing the context package.
 *
 * @internal Wiring seam — the public grammar is AzGuard::forUser()->inContext(...).
 */
final readonly class ContextGrantBuilderFactory implements ContextGrantBuilderFactoryContract
{
    #[Override]
    public function forUser(Authenticatable $user): ContextGrantBuilderContract
    {
        return new ContextGrantBuilder(user: $user);
    }
}
