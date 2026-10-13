<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use Illuminate\Contracts\Container\Container;

/**
 * Scope membership `ScopesPlugin` contributes when the panel requires it: a request inside an assignment scope needs a
 * subject that belongs to that scope, grants or not, super-administrators included. Lists use the membership's own
 * exact predicate.
 */
final readonly class ScopeMembershipRestriction implements FiltersAccessQueries, Restriction
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'membership';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return ScopeConfiguration::membership($context->panel()) !== null && ! $context->scope()->context->isGlobal();
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        $membership = $this->membership($context);

        if ($membership !== null && ! $context->scope()->context->isGlobal()
            && ! $membership->isMember($request->subject(), $context->scope()->context)) {
            return RestrictionResult::deny('assignment_scope_membership');
        }

        return RestrictionResult::pass();
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): AccessPredicate
    {
        $membership = $this->membership($context);

        if ($membership === null || ($context->panel()->scopeDeclaration()->mode ?? AssignmentScopeMode::None) === AssignmentScopeMode::None) {
            return AccessPredicate::pass();
        }

        if (! $membership instanceof FiltersAccessQueries) {
            throw new VisibilityNotSupportedException('missing_exact_adapter', $membership::class);
        }

        return $membership->predicate($request, $resourceType, $context, $contribution);
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }

    private function membership(EvaluationContext $context): ?AssignmentScopeMembership
    {
        $declared = ScopeConfiguration::membership($context->panel());

        if ($declared === null) {
            return null;
        }
        $membership = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $membership instanceof AssignmentScopeMembership) {
            throw new DefinitionException('Scope membership resolver did not return '.AssignmentScopeMembership::class.'.');
        }

        return $membership;
    }
}
