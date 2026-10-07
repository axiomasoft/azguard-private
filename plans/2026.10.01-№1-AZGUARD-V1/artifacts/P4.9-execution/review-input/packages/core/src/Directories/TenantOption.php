<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Kernel\Identity\TenantRef;

/**
 * One tenant a directory offers to the interface.
 *
 * @api
 */
final readonly class TenantOption
{
    public function __construct(
        public TenantRef $tenant,
        public string $label,
        public ?string $description = null,
    ) {}
}
