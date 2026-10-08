<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Resolvers;

use AzGuard\Contracts\Subjects\SubjectResolver;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\Contracts\SubjectResolverContractTests;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;
use AzGuard\Tests\TestCase;

final class SubjectResolverContractTest extends TestCase
{
    use SubjectResolverContractTests;

    protected function azguardSubjectResolver(): SubjectResolver
    {
        return new GoodSubjectResolver;
    }

    protected function azguardSubjects(): array
    {
        return [FakeSubject::of(1), FakeSubject::of(3), SubjectRef::of(FakeSubject::TYPE, 2)];
    }
}
