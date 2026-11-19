<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Kernel\Identity\SubjectRef;
use Packman\Attributes\Api;

/**
 * One subject a directory offers to the interface.
 */
#[Api(since: '1.0')]
final readonly class SubjectOption
{
    public function __construct(
        public SubjectRef $subject,
        public string $label,
        public ?string $description = null,
    ) {}
}
