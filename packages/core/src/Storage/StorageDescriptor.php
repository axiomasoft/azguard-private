<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\Identity\InvalidIdentityException;
use AzGuard\Storage\Internal\Schema\HostKeyColumns;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * What identifies one AzGuard storage: its id, the name of the database connection it resolved to when it was created (a later change of the default
 * connection does not move it), the table prefix
 * and the type of the host keys its tables store (`string`, `bigint`, `uuid` or `ulid`).
 */
#[Api(since: '1.0')]
final readonly class StorageDescriptor
{
    /*
 */
    #[Internal('built by core')]
    public function __construct(
        public string $id,
        public string $connection,
        public string $prefix,
        public string $hostKeys,
    ) {}

    /**
     * Canonical stored form of a host key for this storage: an integer for `bigint` host keys, the canonical text
     * otherwise.
     *
     * @throws InvalidIdentityException when the key is not in the canonical form of the host key type
     */
    public function canonicalKey(int|string $key): string|int
    {
        $canonical = HostKeyColumns::canonical($this->hostKeys, $key);

        return $this->hostKeys === 'bigint' ? (int) $canonical : $canonical;
    }
}
