<?php

declare(strict_types=1);

namespace AzGuard\Filament\Support;

use UnexpectedValueException;

/**
 * @internal Narrows a value of a table record or a form state; Filament passes both as untyped arrays.
 */
final class RecordValue
{
    public static function string(mixed $value, string $what): string
    {
        if (! is_string($value)) {
            throw self::unexpected($what, 'a string', $value);
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $what): ?string
    {
        return $value === null ? null : self::string($value, $what);
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw self::unexpected($what, 'an array', $value);
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw self::unexpected($what, 'an array with string keys', $value);
            }
            $map[$key] = $item;
        }

        return $map;
    }

    private static function unexpected(string $what, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(ucfirst($what).' must be '.$expected.', '.get_debug_type($value).' given.');
    }
}
