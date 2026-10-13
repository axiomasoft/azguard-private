<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Attributes\Api;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * One tenant a directory offers to the interface.
 */
#[Api(since: '1.0')]
final readonly class TenantOption
{
    public function __construct(
        public TenantRef $tenant,
        public string $label,
        public ?string $description = null,
    ) {}
}
