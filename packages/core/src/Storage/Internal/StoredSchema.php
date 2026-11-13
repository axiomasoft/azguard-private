<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use AzGuard\Exceptions\StorageMismatchException;
use Throwable;

/** @internal The `storage_state` row compared with the schema this process expects. */
final class StoredSchema
{
    private function __construct() {}

    public static function decoded(?object $row): mixed
    {
        return $row !== null && isset($row->schema) && is_string($row->schema)
            ? json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR)
            : null;
    }

    /**
     * @param  array<string, int|string>  $expected
     */
    public static function matches(string $storageId, array $expected, mixed $found): void
    {
        if (! is_array($found) || count($found) !== count($expected)
            || array_filter($expected, static fn (int|string $value, string $key): bool => ($found[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
            throw new StorageMismatchException('Storage '.$storageId.' expected '.json_encode($expected).'; found '.json_encode($found).'.');
        }
    }

    /**
     * @param  array<string, int|string>  $expected
     */
    public static function unreadable(string $storageId, array $expected, Throwable $error): never
    {
        throw new StorageMismatchException('Storage '.$storageId.' expected '.json_encode($expected).'; cannot read storage_state: '.$error->getMessage(), 0, $error);
    }
}
