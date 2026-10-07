<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

/** Walks an exported schema and names every value that is not null, a boolean, a number, a string or an array. */
final class SchemaAssertions
{
    /**
     * @return list<string> offenders as "<path>: <type>"
     */
    public static function nonScalars(mixed $value, string $path = '$'): array
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return [];
        }

        if (! is_array($value)) {
            return [$path.': '.get_debug_type($value)];
        }
        $offenders = [];

        foreach ($value as $key => $item) {
            $offenders = [...$offenders, ...self::nonScalars($item, $path.'.'.$key)];
        }

        return $offenders;
    }

    /** The schema as the frontend receives it, in the format of the stored snapshot. */
    public static function json(mixed $schema): string
    {
        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
