<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;

/** A resolver whose type has a colon. */
final class ColonSubjectResolver extends GoodSubjectResolver
{
    public function resolve(mixed $subject): SubjectRef
    {
        return SubjectRef::of('crm:user', 1);
    }
}
