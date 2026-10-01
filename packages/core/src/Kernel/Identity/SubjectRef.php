<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidIdentityException;

/**
 * The subject a decision is about: a type alias of its identity domain and a canonical id.
 */
final readonly class SubjectRef
{
    private function __construct(
        private string $type,
        private string $id,
    ) {}

    /**
     * @throws InvalidIdentityException
     */
    public static function of(string $type, int|string $id): self
    {
        IdentityCodec::assertTypeAlias($type);

        return new self($type, IdentityCodec::canonicalId($id));
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
