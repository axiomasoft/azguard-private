<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * One stored dynamic permission as the writer read it under the panel lock.
 *
 * `id` is `action:<n>`. The permission exists in one tenant only and is always decided by grants.
 *
 * @api
 */
final readonly class PermissionRecord
{
    /**
     * @internal built by the writer
     *
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public string $id,
        public string $panel,
        public TenantRef $tenant,
        public PermissionKey $key,
        public ?string $label,
        public ?string $group,
        public ?string $description,
        public array $fields,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
