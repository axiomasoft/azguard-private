<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Direct grants a source gives a subject.
 *
 * @spi
 */
interface ProvidesGrants extends Source
{
    /**
     * @param  list<AccessScope>  $scopes
     * @return iterable<Grant>
     */
    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable;

    public function volatility(): Volatility;
}
