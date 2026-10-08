<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;

/** A source that gives the permission on every other read. */
final class FlipFlopSource implements ProvidesGrants
{
    public static int $reads = 0;

    public function id(): string
    {
        return 'flip-flop';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if (self::$reads++ % 2 === 0) {
            foreach ($scopes as $scope) {
                yield Grant::of(PermissionPattern::of($context->panel()->id(), 'items.view'), $this->id(), $scope);
            }
        }
    }
}
