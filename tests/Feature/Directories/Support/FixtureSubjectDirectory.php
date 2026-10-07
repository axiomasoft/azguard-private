<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Directories\LookupContext;
use AzGuard\Directories\SubjectOption;
use AzGuard\Kernel\Identity\SubjectRef;

final class FixtureSubjectDirectory implements SubjectDirectory
{
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array
    {
        return [new SubjectOption(SubjectRef::of('crm.user', 1), 'custom:'.$term)];
    }

    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption
    {
        return new SubjectOption($subject, 'custom');
    }
}
