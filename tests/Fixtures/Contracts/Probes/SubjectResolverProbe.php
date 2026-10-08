<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Subjects\SubjectResolver;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\Contracts\SubjectResolverContractTests;
use AzGuard\Testing\FakeSubject;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class SubjectResolverProbe
{
    use RunsAsAProbe;
    use SubjectResolverContractTests;

    /** @param Closure(): SubjectResolver $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardSubjectResolver(): SubjectResolver
    {
        return ($this->make)();
    }

    protected function azguardSubjects(): array
    {
        return [FakeSubject::of(1), SubjectRef::of(FakeSubject::TYPE, 2)];
    }
}
