<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Permissions;

use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use DateTimeImmutable;

/**
 * The patterns a subject holds, deduplicated, with an optional absolute deadline of the set.
 */
final readonly class PermissionSet
{
    /**
     * @param  list<PermissionPattern>  $patterns
     */
    private function __construct(
        private array $patterns,
        private ?DateTimeImmutable $validUntil,
    ) {}

    /**
     * @param  iterable<PermissionPattern>  $patterns
     */
    public static function of(iterable $patterns, ?DateTimeImmutable $validUntil = null): self
    {
        $unique = [];

        foreach ($patterns as $pattern) {
            $unique[$pattern->full()] ??= $pattern;
        }

        return new self(array_values($unique), $validUntil);
    }

    /**
     * @return list<PermissionPattern>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function covers(PermissionKey $key): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->covers($key)) {
                return true;
            }
        }

        return false;
    }

    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }
}
