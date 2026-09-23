<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use AzGuard\Registry\Resolver\PermissionStateRevision;

final class IdlePermissionStateRevision extends PermissionStateRevision
{
    public function __construct(
        public int $revision = 1,
        public bool $inTransaction = false,
    ) {}

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function current(): int
    {
        return $this->revision;
    }
}
