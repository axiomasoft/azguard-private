<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Support;

use UnexpectedValueException;

/**
 * @internal Runtime narrowing of values whose static type is `mixed`: database rows, decoded JSON, Livewire state and
 * container results. A value of another type is a broken invariant and throws; nothing is cast silently.
 */
final class Narrow
{
    /** A string, or an integer as its decimal string (drivers return integer columns either way). */
    public static function string(mixed $value, string $what): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => throw self::unexpected($what, 'a string', $value),
        };
    }

    public static function nullableString(mixed $value, string $what): ?string
    {
        return $value === null ? null : self::string($value, $what);
    }

    /** An integer, or a string of decimal digits (PDO returns integers as strings on some drivers). */
    public static function int(mixed $value, string $what): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 => (int) $value,
            default => throw self::unexpected($what, 'an integer', $value),
        };
    }

    public static function bool(mixed $value, string $what): bool
    {
        return is_bool($value) ? $value : throw self::unexpected($what, 'a boolean', $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw self::unexpected($what, 'an array', $value);
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw self::unexpected($what.' key', 'a string', $key);
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * The public properties of an object by name, such as the columns of a database row. It reads from the scope of
     * this class: a caller that needs its own private or protected state narrows `get_object_vars()` with `map()`.
     *
     * @return array<string, mixed>
     */
    public static function row(object $row): array
    {
        $columns = [];
        foreach (get_object_vars($row) as $name => $value) {
            $columns[(string) $name] = $value;
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function maps(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw self::unexpected($what, 'an array', $value);
        }

        return array_values(array_map(static fn (mixed $item): array => self::map($item, $what.' item'), $value));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function instance(mixed $value, string $class, string $what): object
    {
        return $value instanceof $class ? $value : throw self::unexpected($what, $class, $value);
    }

    private static function unexpected(string $what, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException($what.' must be '.$expected.', got '.get_debug_type($value).'.');
    }
}
