<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wiring seam between the core grant root and the optional azguard/context
 * package: `GrantBuilder::inContext()` resolves this factory from the
 * container to hand the fluent chain over to the context branch.
 *
 * Bound by AzGuardContextServiceProvider; not bound when the context
 * package is absent. A genuine swap seam: an alternative context-grant
 * storage may bind its own factory. Consumers do not call it directly —
 * the public grammar is {@see ContextGrantBuilder}.
 *
 * @api
 */
interface ContextGrantBuilderFactory
{
    public function forUser(Authenticatable $user): ContextGrantBuilder;
}
