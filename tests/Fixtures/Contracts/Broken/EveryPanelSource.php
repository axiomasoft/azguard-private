<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;

/** A source that gives the permission of the panel `contract` to every panel it is attached to. */
final class EveryPanelSource implements ProvidesGrants
{
    public function id(): string
    {
        return 'every-panel';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        foreach ($scopes as $scope) {
            yield Grant::of(PermissionPattern::of('contract', 'items.view'), $this->id(), $scope);
        }
    }
}
