<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use Illuminate\Contracts\Container\Container;

/** Active membership is independent of grants and remains mandatory for administrative candidates. */
final readonly class MembershipRestriction implements Restriction
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'membership';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return $context->panel()->tenants()->membership() !== null || $context->panel()->scopes()->membership() !== null;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        $tenant = $context->panel()->tenants()->membership();

        if ($tenant !== null && ! $context->scope()->tenant->isGlobal()) {
            $adapter = is_string($tenant) ? $this->container->make($tenant) : $tenant;

            if (! $adapter instanceof TenantMembership) {
                throw new DefinitionException('Tenant membership resolver did not return '.TenantMembership::class.'.');
            }

            if (! $adapter->isMember(subject: $request->subject(), tenant: $context->scope()->tenant)) {
                return RestrictionResult::deny('tenant_membership');
            }
        }

        $scope = $context->panel()->scopes()->membership();

        if ($scope !== null && ! $context->scope()->context->isGlobal()) {
            $adapter = is_string($scope) ? $this->container->make($scope) : $scope;

            if (! $adapter instanceof AssignmentScopeMembership) {
                throw new DefinitionException('Scope membership resolver did not return '.AssignmentScopeMembership::class.'.');
            }

            if (! $adapter->isMember(subject: $request->subject(), context: $context->scope()->context)) {
                return RestrictionResult::deny('assignment_scope_membership');
            }
        }

        return RestrictionResult::pass();
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
