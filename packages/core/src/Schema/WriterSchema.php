<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Attributes\Api;

/**
 * What the writer of a panel can do, for interfaces that must not know the source class behind it: the id of the
 * storage it writes to, whether it keeps dynamic permissions and whether it stores role grants only.
 */
#[Api(since: '1.0')]
final readonly class WriterSchema
{
    public function __construct(
        public string $storage,
        public bool $dynamicPermissions,
        public bool $rolesOnly,
    ) {}
}
