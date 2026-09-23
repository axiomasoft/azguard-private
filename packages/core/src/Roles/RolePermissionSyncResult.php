<?php

declare(strict_types=1);

namespace AzGuard\Roles;

final readonly class RolePermissionSyncResult
{
    public function __construct(
        public int $added,
        public int $removed,
    ) {}

    public function changed(): bool
    {
        return $this->added !== 0 || $this->removed !== 0;
    }
}
