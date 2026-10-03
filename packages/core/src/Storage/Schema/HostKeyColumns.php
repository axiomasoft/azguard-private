<?php

declare(strict_types=1);

namespace AzGuard\Storage\Schema;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\IdentityCodec;

final class HostKeyColumns
{
    public static function canonical(string $hostKeys, int|string $id): string
    {
        $value = (string) $id;
        $valid = match ($hostKeys) {
            'string' => IdentityCodec::canonicalId($id) === $value,
            'bigint' => preg_match('/\A(0|[1-9][0-9]*)\z/', $value) === 1
                && (strlen($value) < strlen((string) PHP_INT_MAX)
                    || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) <= 0)),
            'uuid' => preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1,
            'ulid' => preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $value) === 1,
            default => false,
        };

        if (! $valid) {
            throw new InvalidIdentityException('Noncanonical '.$hostKeys.' host key: '.$value.'.');
        }

        return $value;
    }
}
