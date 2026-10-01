<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use InvalidArgumentException;

/**
 * Outcome of a restriction: pass, or deny with a snake_case reason. A restriction never allows.
 */
final readonly class RestrictionResult
{
    private function __construct(
        private ?string $reason,
    ) {}

    public static function pass(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidArgumentException when the reason is not snake_case
     */
    public static function deny(string $reason): self
    {
        if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $reason) !== 1) {
            throw new InvalidArgumentException('Restriction deny reason must be snake_case, got '.json_encode($reason).'.');
        }

        return new self($reason);
    }

    public function denied(): bool
    {
        return $this->reason !== null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
