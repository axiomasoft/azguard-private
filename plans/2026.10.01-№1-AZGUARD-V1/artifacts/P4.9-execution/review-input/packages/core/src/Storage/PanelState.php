<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use DateTimeImmutable;

final readonly class PanelState
{
    public function __construct(
        public string $panel,
        public int $version,
        public string $incarnation,
        public DateTimeImmutable $updatedAt,
    ) {}
}
