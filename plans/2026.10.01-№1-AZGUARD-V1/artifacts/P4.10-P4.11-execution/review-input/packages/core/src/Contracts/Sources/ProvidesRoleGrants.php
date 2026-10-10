<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Roles a source assigns, including a superadmin role with no permissions of its own.
 *
 * @spi
 */
interface ProvidesRoleGrants extends Source
{
    /**
     * @param  list<AccessScope>  $scopes
     * @return iterable<RoleContribution>
     */
    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable;

    public function volatility(): Volatility;
}
