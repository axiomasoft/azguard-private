<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Identity\SubjectRef;

/** A source whose id differs from one call to the next. */
final class DriftingIdSource implements ProvidesGrants
{
    private int $calls = 0;

    public function id(): string
    {
        return 'drift-'.$this->calls++;
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        return [];
    }
}
