<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value Object: is a type-safe value with an invariant that is checked in the constructor.
 * - final readonly — immutable, no setters; The
 * - invariant ensures that an UNVALID instance does not exist;
 * - equality by value (equals), not by identity;
 * - domain noun in name (Email, Money), without suffix -VO/-Object.
 */
final readonly class Email
{
    public function __construct(public string $value)
    {
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(message: "Invalid email: {$value}");
        }
    }

    public function domain(): string
    {
        return substr(string: $this->value, offset: strrpos(haystack: $this->value, needle: '@') + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * VO with multiple fields: an invariant links them (amount + currency).
 * Behavior (operations on value) lives in VO, and not spread across services.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,        // in minimum units (kopecks/cents)
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException(message: 'Amount cannot be negative');
        }
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(message: 'You cannot add different currencies');
        }

        return new self(amount: $this->amount + $other->amount, currency: $this->currency);
    }
}
