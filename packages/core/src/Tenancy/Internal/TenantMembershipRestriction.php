<?php

declare(strict_types=1);

namespace AzGuard\Tenancy\Internal;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Tenancy\TenantMembership;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use Illuminate\Contracts\Container\Container;

/** Active tenant membership is independent of grants and remains mandatory for administrative candidates. */
final readonly class TenantMembershipRestriction implements Restriction
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'membership';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return $context->panel()->tenants()->membership() !== null;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        $tenant = $context->panel()->tenants()->membership();

        if ($tenant !== null && ! $context->scope()->tenant->isGlobal()) {
            $adapter = is_string($tenant) ? $this->container->make($tenant) : $tenant;

            if (! $adapter instanceof TenantMembership) {
                throw new DefinitionException('Tenant membership resolver did not return '.TenantMembership::class.'.');
            }

            if (! $adapter->isMember($request->subject(), $context->scope()->tenant)) {
                return RestrictionResult::deny('tenant_membership');
            }
        }

        return RestrictionResult::pass();
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
