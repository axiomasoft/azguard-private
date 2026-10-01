<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidIdentityException;

/**
 * A tenant: the data boundary of an organization, or the global tenant.
 *
 * `global()` is a separate form with key `global`; `of('global', ...)` is an ordinary reference.
 */
final readonly class TenantRef
{
    private const string GLOBAL_KEY = 'global';

    private function __construct(
        private ?string $type,
        private ?string $id,
    ) {}

    /**
     * @throws InvalidIdentityException
     */
    public static function of(string $type, int|string $id): self
    {
        IdentityCodec::assertTypeAlias($type, InvalidIdentityException::class);

        return new self($type, IdentityCodec::canonicalId($id, InvalidIdentityException::class));
    }

    public static function global(): self
    {
        return new self(null, null);
    }

    public function isGlobal(): bool
    {
        return $this->type === null;
    }

    public function key(): string
    {
        return $this->type === null ? self::GLOBAL_KEY : $this->type.':'.$this->id;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
