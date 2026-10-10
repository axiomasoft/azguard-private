<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

use AzGuard\Exceptions\InvalidIdentityException;

/**
 * Who initiated a change: a host identity, or the system actor without an id and with a reason.
 */
final readonly class ActorRef
{
    public const string SYSTEM_TYPE = 'azguard.system';

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
}
