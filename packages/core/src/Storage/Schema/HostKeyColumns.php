<?php

declare(strict_types=1);

namespace AzGuard\Storage\Schema;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\IdentityCodec;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

final class HostKeyColumns
{
    public static function identifier(Blueprint $table, string $name, int $length, string $driver): ColumnDefinition
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $table->binary($name, $length);
        }
        $column = $table->string($name, $length);

        if ($driver === 'pgsql') {
            $column->collation('C');
        }

        return $column;
    }

    public static function hostKey(Blueprint $table, string $name, string $hostKeys, string $driver): ColumnDefinition
    {
        return match ($hostKeys) {
            'bigint' => $table->bigInteger($name),
            'uuid' => in_array($driver, ['mysql', 'mariadb'], true)
                ? $table->binary($name, 36) : $table->uuid($name),
            'ulid' => in_array($driver, ['mysql', 'mariadb'], true)
                ? $table->binary($name, 26) : $table->char($name, 26)->collation($driver === 'pgsql' ? 'C' : 'BINARY'),
            default => self::identifier($table, $name, 64, $driver),
        };
    }

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
