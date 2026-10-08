<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;

/** A resolver that turns anything into a reference. */
final class PermissiveSubjectResolver extends GoodSubjectResolver
{
    public function resolve(mixed $subject): SubjectRef
    {
        return $subject instanceof FakeSubject || $subject instanceof SubjectRef ? parent::resolve($subject) : SubjectRef::of(FakeSubject::TYPE, 1);
    }
}
