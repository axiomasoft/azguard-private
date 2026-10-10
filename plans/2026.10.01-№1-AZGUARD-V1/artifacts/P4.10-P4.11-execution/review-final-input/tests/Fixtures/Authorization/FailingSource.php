<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Identity\SubjectRef;
use RuntimeException;

final class FailingSource extends GeneratedSource
{
    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantReads++;

        throw new RuntimeException('source unavailable');
    }
}
