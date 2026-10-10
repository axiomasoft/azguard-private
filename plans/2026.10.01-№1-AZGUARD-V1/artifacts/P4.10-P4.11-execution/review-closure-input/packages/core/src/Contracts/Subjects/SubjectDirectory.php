<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Subjects;

use AzGuard\Directories\LookupContext;
use AzGuard\Directories\SubjectOption;
use AzGuard\Kernel\Identity\SubjectRef;

/**
 * Search of subjects for the interface.
 *
 * @spi
 */
interface SubjectDirectory
{
    /**
     * @return list<SubjectOption>
     */
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array;

    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption;
}
