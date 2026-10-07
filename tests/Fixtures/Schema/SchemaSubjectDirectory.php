<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Directories\LookupContext;
use AzGuard\Directories\SubjectOption;
use AzGuard\Kernel\Identity\SubjectRef;

final class SchemaSubjectDirectory implements SubjectDirectory
{
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array
    {
        return [];
    }

    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption
    {
        return null;
    }
}
