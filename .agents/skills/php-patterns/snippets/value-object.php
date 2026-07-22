<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value Object: типобезопасное значение с инвариантом, проверяемым в конструкторе.
 * - final readonly — иммутабельность, без сеттеров;
 * - инвариант гарантирует, что НЕвалидного экземпляра не существует;
 * - равенство по значению (equals), не по идентичности;
 * - доменное существительное в имени (Email, Money), без суффикса -VO/-Object.
 */
final readonly class Email
{
    public function __construct(public string $value)
    {
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(message: "Невалидный email: {$value}");
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
 * VO с несколькими полями: инвариант связывает их (amount + currency).
 * Поведение (операции над значением) живёт в VO, а не размазано по сервисам.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,        // в минимальных единицах (копейки/центы)
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException(message: 'Сумма не может быть отрицательной');
        }
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(message: 'Нельзя складывать разные валюты');
        }

        return new self(amount: $this->amount + $other->amount, currency: $this->currency);
    }
}
