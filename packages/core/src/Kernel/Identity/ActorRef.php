<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Attributes\Api;
use AzGuard\Exceptions\InvalidIdentityException;

/**
 * Who initiated a change: a host identity, or the system actor without an id and with a reason.
 */
#[Api(since: '1.0')]
final readonly class ActorRef
{
    public const string SYSTEM_TYPE = 'azguard:system';

    private function __construct(
        public string $type,
        public ?string $id,
        public ?string $reason,
    ) {}

    /**
     * @throws InvalidIdentityException when the type is invalid or reserved for the system actor
     */
    public static function of(string $type, int|string $id, ?string $reason = null): self
    {
        IdentityCodec::assertTypeAlias($type);

        if ($type === self::SYSTEM_TYPE) {
            throw new InvalidIdentityException('Invalid actor type "'.self::SYSTEM_TYPE.'": reserved, use ActorRef::system().');
        }

        return new self($type, IdentityCodec::canonicalId($id), $reason);
    }

    public static function system(?string $reason = null): self
    {
        return new self(self::SYSTEM_TYPE, null, $reason);
    }

    /** Strict on every field. Object `==` treats distinct canonical ids such as `007` and `7` as the same actor. */
    public function equals(?self $other): bool
    {
        return $other !== null && $this->type === $other->type && $this->id === $other->id && $this->reason === $other->reason;
    }
}
