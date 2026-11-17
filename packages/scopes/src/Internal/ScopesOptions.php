<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;

/**
 * Scope behavior settings `ScopesPlugin` contributes to a panel: the scope membership adapter and the access adapters
 * of external scope types. Read them with `Panel::options(ScopesOptions::class)`.
 */
final readonly class ScopesOptions
{
    /**
     * @param  AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null  $membership
     * @param  array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>>  $adapters  keyed by scope type alias
     */
    public function __construct(
        public AssignmentScopeMembership|string|null $membership = null,
        public array $adapters = [],
    ) {}
}
