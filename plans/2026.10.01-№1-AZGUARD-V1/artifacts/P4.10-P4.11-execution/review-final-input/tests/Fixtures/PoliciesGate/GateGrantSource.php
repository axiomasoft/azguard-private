<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

final class GateGrantSource implements ProvidesGrants
{
    public function id(): string
    {
        return 'native-grants';
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        yield Grant::of(PermissionPattern::of('admin', 'beta.veto'), $this->id(), AccessScope::in(TenantRef::global()));
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }
}
