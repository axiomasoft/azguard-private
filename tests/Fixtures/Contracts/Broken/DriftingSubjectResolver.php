<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;

/** A resolver that gives a new reference for the same subject. */
final class DriftingSubjectResolver extends GoodSubjectResolver
{
    private int $calls = 0;

    public function resolve(mixed $subject): SubjectRef
    {
        return SubjectRef::of(FakeSubject::TYPE, 1 + $this->calls++);
    }
}
