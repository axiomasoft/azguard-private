<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use Illuminate\Support\Carbon;

/** A source that expires its grants an hour after its own clock, not the context's. */
final class OwnClockSource implements ProvidesGrants
{
    public function id(): string
    {
        return 'own-clock';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        foreach ($scopes as $scope) {
            yield Grant::of(PermissionPattern::of($context->panel()->id(), 'items.view'), $this->id(), $scope,
                expiresAt: Carbon::now('UTC')->addHour()->toDateTimeImmutable());
        }
    }
}
