<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Identity\SubjectRef;
use RuntimeException;

final class FailingSource implements ProvidesGrants
{
    public function id(): string
    {
        return 'broken';
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        throw new RuntimeException('CRM source unavailable');
    }
}
